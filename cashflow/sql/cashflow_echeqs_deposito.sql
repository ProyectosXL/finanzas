/* ============================================================================
   MODULO CASHFLOW - ECHEQS: DEPOSITO MASIVO DE CHEQUES DE CARTERA
   Crea el registro de depositos, el juego de alicuotas de los cheques y el
   plazo de acreditacion
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_
   Orden   : despues de sql/cashflow_auditoria_usuario.sql (las tablas nacen
             con su esquema de auditoria) y de sql/cashflow_echeqs_excluir.sql
             (un cheque excluido no se puede depositar, y eso se valida contra
             esa tabla). Para que se pueda depositar hace falta ademas la fila
             del tablero: sql/cashflow_estructura_echeqs_depositados.sql. Sin
             ella el boton queda apagado nombrando ese script.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Los cheques de cartera se depositan en el banco por tandas, de un mismo
   banco emisor. Desde que se depositan, la plata ya no entra en la fecha del
   cheque: entra a las 48 hs habiles del deposito, y entra NETA, porque el banco
   retiene IIBB y SICREB sobre el deposito. Hasta ahora el tablero seguia
   mostrando el cheque en su fecha y por el bruto.

   Con esto, depositar un cheque lo saca de la fila "Echeqs en cartera" y lo
   lleva a "Echeqs depositados", el dia que acredita y por el neto. Ver
   README-echeqs.md.

   ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_ECHEQ_DEPOSITO

   Una fila por cheque depositado, con todo lo que define el deposito CALCULADO
   POR EL SERVIDOR Y PERSISTIDO: banco, fechas, plazo, bruto, tasa y neto. El
   navegador solo manda los ids de los cheques.

   LA FECHA DE ACREDITACION SE GUARDA, NO SE RECALCULA AL LEER. Sale del plazo
   de Parametros vigente el dia del deposito; cambiar el plazo despues no puede
   mover depositos ya hechos, porque el banco ya los tomo con su propio plazo.
   DIAS_PLAZO guarda con cuantos dias se calculo, para que esa fecha se pueda
   explicar.

   LA TASA Y EL NETO SE GUARDAN, como en Cob. Electronicos: un deposito no puede
   quedar sin neto, y editar una alicuota no reescribe lo ya acreditado. Los
   pendientes -los que acreditan de manana en adelante- SI se recalculan al
   cambiar una alicuota, y ahi se reescriben TASA_APLICADA, DETALLE_TASA e
   IMPORTE_NETO. DETALLE_TASA es el desglose ("IIBB 2,5% + SICREB 0,6%") con el
   que se calculo: una tasa sin desglose no se puede auditar.

   EL BANCO SE GUARDA COMO ESTABA AL DEPOSITAR. Es el dato que agrupo la tanda
   -todos los cheques de un deposito son del mismo banco emisor- y no tiene que
   depender de que dbo.BANCO siga diciendo lo mismo.

   NO HAY BAJAS FISICAS, Y EL HISTORIAL ES EL PUNTO. Deshacer un deposito marca
   VIGENTE = 0 con quien y cuando; volver a depositarlo inserta una fila nueva.
   Es el mismo criterio que RO_T_CASHFLOW_ECHEQ_EXCLUIDO: la PK es un ID y no
   ID_SBA14, porque un cheque puede tener varias filas y solo una vigente.

   SIN FK CONTRA dbo.SBA14, por el mismo motivo que la exclusion: es una tabla
   de Tango, y una FK nuestra haria fallar una depuracion de un sistema que no
   es nuestro. Un deposito huerfano no aparece en ninguna lectura: todas parten
   de los cheques en cartera.

   QUE UN DEPOSITO ESTE VIGENTE NO QUIERE DECIR QUE SE PROYECTE. Deja de
   proyectarse solo, sin que nadie toque la fila, cuando el cheque sale de
   cartera en Tango -se registro el deposito, o se rechazo- o cuando llega su
   dia de acreditacion. Eso lo decide Echeqs::estadoDeposito(), no esta tabla.

   ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_ECHEQ_ALICUOTA

   Las retenciones del banco sobre el deposito de cheques. Es el MISMO mecanismo
   que RO_T_CASHFLOW_COBEL_ALICUOTA -concepto libre, vigencias, la suma de las
   vigentes a la fecha de acreditacion- y la regla vive una sola vez, en
   Class/Retenciones.php.

   ES UNA TABLA PROPIA Y NO FILAS EN LA DE COB. ELECTRONICOS. Aquella cuelga de
   una procesadora, y compartirla exigiria una procesadora "Cheques" que
   apareceria en el alta de movimientos y en el ABM de procesadoras. Con dos
   tablas, un cambio en la tasa de Payway no puede mover el neto de un cheque
   ni al reves, y eso no depende de que nadie se acuerde de filtrar.

   NO HAY UNIQUE (CONCEPTO, VIGENCIA_DESDE) A PROPOSITO, igual que en Cob.
   Electronicos: corregir dos veces el mismo porcentaje el mismo dia es lo que
   pasa cuando alguien se equivoca al tipearlo, y gana la insertada despues
   (desempate por ID).

   NO SIEMBRA NINGUNA ALICUOTA. Los porcentajes del banco los carga Tesoreria
   en Parametros -> Cheques. Mientras no haya ninguna vigente, el deposito se
   rechaza diciendo que falta cargarla: un neto igual al bruto informaria de
   mas sin decirlo.

   ----------------------------------------------------------------------------
   3. EL PLAZO DE ACREDITACION

   'echeqs_dias_acreditacion_deposito' = 2 en RO_T_CASHFLOW_PARAMETROS, modulo
   PRECHEQUEADO -la sub-pestana que ahora se llama Cheques; el codigo no cambio
   para no perder los permisos ya asignados-. Son DIAS HABILES BANCARIOS
   despues del deposito: con 2, lunes -> miercoles y viernes -> martes.

   Si el parametro ya existe NO se toca su VALOR: alguien pudo haberlo editado.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: las dos tablas
   nacen vacias, asi que no hay ningun cheque depositado.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS DEPOSITOS
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO (
        ID                 INT IDENTITY(1,1) NOT NULL,
        /* La PK de dbo.SBA14. N_CHEQUE NO identifica un cheque: se repite entre
           bancos y entre anios. */
        ID_SBA14           INT           NOT NULL,
        /* Copia para leer el historial sin depender de Tango. Es float en
           SBA14; aca va entero, que es lo que es. */
        N_CHEQUE           BIGINT        NULL,
        ID_BANCO           INT           NOT NULL,
        DESC_BANCO         VARCHAR(60)   NULL,

        FECHA_DEPOSITO     DATE          NOT NULL,
        FECHA_ACREDITACION DATE          NOT NULL,
        DIAS_PLAZO         INT           NOT NULL,

        IMPORTE_BRUTO      DECIMAL(18,4) NOT NULL,
        TASA_APLICADA      DECIMAL(9,6)  NOT NULL,
        DETALLE_TASA       VARCHAR(200)  NULL,
        IMPORTE_NETO       DECIMAL(18,4) NOT NULL,

        OBSERVACION        VARCHAR(200)  NULL,

        VIGENTE            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQDEP_VIGENTE DEFAULT (1),

        USUARIO_ALTA       VARCHAR(50)   NULL,
        FECHA_ALTA         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQDEP_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF      VARCHAR(50)   NULL,
        FECHA_MODIF        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQDEP_MODIF DEFAULT (GETDATE()),
        /* Quien deshizo el deposito y cuando. Con FECHA_ALTA sola no se puede
           distinguir un deposito deshecho a los cinco minutos de uno que
           estuvo vigente dos dias. */
        USUARIO_BAJA       VARCHAR(50)   NULL,
        FECHA_BAJA         DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_DEPOSITO PRIMARY KEY CLUSTERED (ID),
        /* El plazo es de al menos un dia habil: con cero el cheque acreditaria
           el mismo dia del deposito, que es justo lo que el plazo existe para
           no suponer. */
        CONSTRAINT CK_RO_T_CF_ECHEQDEP_PLAZO CHECK (DIAS_PLAZO >= 1),
        CONSTRAINT CK_RO_T_CF_ECHEQDEP_FECHAS CHECK (FECHA_ACREDITACION > FECHA_DEPOSITO),
        CONSTRAINT CK_RO_T_CF_ECHEQDEP_BRUTO CHECK (IMPORTE_BRUTO > 0),
        /* Una tasa de 1 o mas dejaria el neto en cero o negativo: un ingreso
           restaria plata del tablero. La suma la valida PHP al guardar la
           alicuota; esto es la ultima red. */
        CONSTRAINT CK_RO_T_CF_ECHEQDEP_TASA CHECK (TASA_APLICADA >= 0 AND TASA_APLICADA < 1)
    );

    /* La lectura de cada carga de la pestana y del tablero es "el deposito
       vigente de cada cheque". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQDEP_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO (VIGENTE, ID_SBA14)
        INCLUDE (FECHA_ACREDITACION, IMPORTE_BRUTO, IMPORTE_NETO);

    /* El recalculo al cambiar una alicuota busca los vigentes que acreditan
       de manana en adelante. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQDEP_ACREDITA
        ON dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO (VIGENTE, FECHA_ACREDITACION);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   UN SOLO DEPOSITO VIGENTE POR CHEQUE.

   Dos depositos vigentes del mismo cheque sumarian dos veces su neto a la fila
   del tablero. PHP ya rechaza depositar un cheque depositado, pero dos
   pestanas abiertas pueden mandar el mismo cheque al mismo tiempo: el indice
   es lo que hace que el segundo falle en la base.

   INDICE UNICO FILTRADO y no constraint: un UNIQUE comun prohibiria tambien
   las filas historicas repetidas, que es lo que esta tabla tiene que guardar.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_ECHEQDEP_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQDEP_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO (ID_SBA14)
        WHERE VIGENTE = 1;

    PRINT 'Creado el indice unico de depositos vigentes.';
END
GO

/* ----------------------------------------------------------------------------
   2. LAS ALICUOTAS DE LOS CHEQUES
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* Libre: hoy IIBB y SICREB. Una retencion nueva es una fila, no un
           CHECK ni un cambio de codigo. */
        CONCEPTO       VARCHAR(30)   NOT NULL,
        /* Fraccion: 2,5% es 0,025. */
        ALICUOTA       DECIMAL(9,6)  NOT NULL,
        VIGENCIA_DESDE DATE          NOT NULL,
        ACTIVO         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_ACTIVO DEFAULT (1),

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)   NULL,
        FECHA_MODIF    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA   VARCHAR(50)   NULL,
        FECHA_BAJA     DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_ALICUOTA PRIMARY KEY CLUSTERED (ID),
        /* Una alicuota sola nunca puede llegar a 1. La suma la valida PHP. */
        CONSTRAINT CK_RO_T_CF_ECHEQALI_RANGO CHECK (ALICUOTA >= 0 AND ALICUOTA < 1)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQALI_VIGENCIA
        ON dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA (CONCEPTO, VIGENCIA_DESDE DESC, ID DESC)
        INCLUDE (ALICUOTA, ACTIVO);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA. No se siembra ninguna alicuota: se cargan en Parametros -> Cheques.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   3. EL PLAZO DE ACREDITACION

   Grupo DEPOSITO y no GENERAL: la sub-pestana no tiene seccion 'generales' -la
   dibujaria con el editor generico, que guarda sin validar- y el plazo se
   edita en su propia seccion, que valida que sea un entero de 1 a 30.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
               WHERE CLAVE = 'echeqs_dias_acreditacion_deposito')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('echeqs_dias_acreditacion_deposito', '2', 'INT',
                'Dias habiles bancarios entre el deposito de un cheque de cartera y su acreditacion',
                'DEPOSITO');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
            VALUES (''echeqs_dias_acreditacion_deposito'', ''2'', ''INT'',
                    ''Dias habiles bancarios entre el deposito de un cheque de cartera y su acreditacion'',
                    ''DEPOSITO'', ''PRECHEQUEADO'');';

    PRINT 'Parametro echeqs_dias_acreditacion_deposito sembrado en 2 dias habiles.';
END
ELSE
BEGIN
    PRINT 'El parametro echeqs_dias_acreditacion_deposito ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   4. Control: el plazo tiene que ser un entero de 1 a 30.

   Se pudo haber editado por fuera de la pantalla. Con un valor invalido la
   pestana no deja depositar y dice por que; esto lo avisa al correr.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE CLAVE = 'echeqs_dias_acreditacion_deposito'
      AND (TRY_CAST(VALOR AS INT) IS NULL OR TRY_CAST(VALOR AS INT) NOT BETWEEN 1 AND 30)
)
BEGIN
    RAISERROR('ATENCION: echeqs_dias_acreditacion_deposito no es un entero de 1 a 30. Corregilo en Parametros -> Cheques: mientras tanto no se puede depositar.', 16, 1);
END
GO

PRINT 'Deposito de cheques de cartera listo.';
GO
