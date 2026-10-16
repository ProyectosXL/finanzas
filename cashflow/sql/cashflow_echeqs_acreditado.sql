/* ============================================================================
   MODULO CASHFLOW - ECHEQS: LA MARCA "YA ACREDITO" DE UN CHEQUE DEPOSITADO
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_
   Orden   : despues de sql/cashflow_auditoria_usuario.sql (la tabla nace con
             su esquema de auditoria). No depende de ningun otro: sin este
             script, la pestana Echeqs no dibuja el boton "Ya acredito", avisa
             que falta correrlo, y los depositados se proyectan hasta que vence
             su plazo.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Un cheque que Tesoreria deposito en Tango (boleta BDE) se proyecta en la
   fila "Echeqs depositados" hasta su fecha de acreditacion: la boleta mas el
   plazo habil de Parametros -> Cheques, hoy 2 dias. Si el banco acredita antes,
   esa plata ya esta en el saldo de Interbanking -que ahora se actualiza cada
   hora- y el tablero la contaria dos veces: en el saldo y en la fila.

   Con esto, Tesoreria marca "ya acredito" y el cheque deja de proyectarse. Se
   sigue viendo en la grilla, con la marca "acreditado", hasta su fecha de
   acreditacion; despues deja de leerse, como cualquier depositado.

   ----------------------------------------------------------------------------
   LA REGLA NO DEPENDE DEL ORIGEN, Y LA TABLA ESTA HECHA PARA DOS

   ORIGEN dice quien grabo la marca:

     MANUAL        una persona, desde la pestana Echeqs.
     INTERBANKING  un proceso que lea la API de Interbanking y encuentre la
                   acreditacion del cheque en el extracto. Todavia no existe.

   Una marca vigente saca al cheque de "Echeqs depositados" sea cual sea su
   origen: lo decide Echeqs::resolverDeposito(), que no mira esta columna. La
   pantalla la muestra en el tooltip, y una marca de Interbanking se puede
   deshacer a mano igual que una manual.

   FECHA_ACREDITACION es la fecha en que acredito: la del dia en que se marco
   a mano, o la que informe el origen.

   REFERENCIA es el identificador del movimiento en el origen -el de
   Interbanking-; en una marca manual va NULL. EL PROCESO DE INTERBANKING NO
   PUEDE VOLVER A GRABAR UNA REFERENCIA QUE YA EXISTE EN ESTA TABLA, AUNQUE ESTE
   DADA DE BAJA. Es lo que hace que un deshacer a mano se respete: si alguien da
   de baja una marca de Interbanking porque el proceso se equivoco de cheque,
   la proxima corrida no puede volver a ponerla. El indice unico filtrado de
   abajo lo asegura en la base para las filas con REFERENCIA.

   ID_REEMPLAZA apunta a la marca que esta reemplaza: por ejemplo, la de
   Interbanking que confirma una manual. El proceso da de baja la anterior e
   inserta la suya con ID_REEMPLAZA, y el historial cuenta las dos.

   ----------------------------------------------------------------------------
   NO HAY BAJAS FISICAS

   Deshacer marca VIGENTE = 0 con quien y cuando (USUARIO_BAJA, FECHA_BAJA); el
   cheque vuelve a proyectarse si su acreditacion todavia es de manana en
   adelante. Marcarlo de nuevo inserta una fila nueva. Es el criterio de
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO: la PK es un ID y no ID_SBA14, porque un cheque
   puede tener varias filas en el tiempo y una sola vigente.

   SIN FK CONTRA dbo.SBA14, por el mismo motivo que la exclusion: es una tabla
   de Tango, y una FK nuestra haria fallar una depuracion de un sistema que no
   es nuestro. Una marca huerfana no aparece en ninguna lectura: todas parten
   de los cheques depositados.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: la tabla nace
   vacia, asi que ningun depositado esta marcado.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LAS MARCAS
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO (
        ID                 INT IDENTITY(1,1) NOT NULL,
        /* La PK de dbo.SBA14. N_CHEQUE NO identifica un cheque: se repite entre
           bancos y entre anios. */
        ID_SBA14           INT           NOT NULL,
        FECHA_ACREDITACION DATE          NOT NULL,
        ORIGEN             VARCHAR(20)   NOT NULL,
        /* El id del movimiento en el origen. NULL en una marca manual. */
        REFERENCIA         VARCHAR(100)  NULL,
        OBSERVACION        VARCHAR(200)  NULL,
        VIGENTE            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQACR_VIGENTE DEFAULT (1),
        ID_REEMPLAZA       INT           NULL,

        USUARIO_ALTA       VARCHAR(50)   NULL,
        FECHA_ALTA         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQACR_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF      VARCHAR(50)   NULL,
        FECHA_MODIF        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQACR_MODIF DEFAULT (GETDATE()),
        /* Quien deshizo la marca y cuando. */
        USUARIO_BAJA       VARCHAR(50)   NULL,
        FECHA_BAJA         DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_ACREDITADO PRIMARY KEY CLUSTERED (ID),
        /* Los origenes que hay. Uno nuevo es un cambio de este CHECK a
           proposito: un origen tipeado mal dejaria una marca que nadie sabe de
           donde vino. */
        CONSTRAINT CK_RO_T_CF_ECHEQACR_ORIGEN CHECK (ORIGEN IN ('MANUAL', 'INTERBANKING')),
        CONSTRAINT FK_RO_T_CF_ECHEQACR_REEMPLAZA FOREIGN KEY (ID_REEMPLAZA)
            REFERENCES dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO (ID)
    );

    /* La lectura de cada carga de la pestana y del tablero es "las marcas de
       estos cheques", vigentes o no -el tooltip cuenta las deshechas-. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQACR_CHEQUE
        ON dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO (ID_SBA14, VIGENTE);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA SOLA MARCA VIGENTE POR CHEQUE.

   PHP ya rechaza marcar un cheque marcado, pero dos pestanas -o la pantalla y
   el proceso de Interbanking- pueden marcarlo al mismo tiempo: el indice es lo
   que hace que la segunda falle en la base.

   INDICE UNICO FILTRADO y no constraint: un UNIQUE comun prohibiria tambien
   las filas historicas repetidas, que es lo que esta tabla tiene que guardar.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_ECHEQACR_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQACR_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO (ID_SBA14)
        WHERE VIGENTE = 1;

    PRINT 'Creado el indice unico de marcas vigentes.';
END
GO

/* ----------------------------------------------------------------------------
   3. UNA REFERENCIA DEL ORIGEN SE GRABA UNA SOLA VEZ, VIGENTE O NO.

   Filtrado por REFERENCIA IS NOT NULL: las marcas manuales no tienen y pueden
   ser muchas. Sin VIGENTE en el filtro, a proposito: una marca de Interbanking
   deshecha a mano bloquea que el proceso vuelva a grabar la misma
   referencia. Ver el encabezado.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_ECHEQACR_REFERENCIA'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQACR_REFERENCIA
        ON dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO (ORIGEN, REFERENCIA)
        WHERE REFERENCIA IS NOT NULL;

    PRINT 'Creado el indice unico de referencias del origen.';
END
GO

PRINT 'Marca "ya acredito" de los echeqs depositados lista.';
GO
