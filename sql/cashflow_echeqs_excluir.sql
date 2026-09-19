/* ============================================================================
   MODULO CASHFLOW - ECHEQS: EXCLUIR UN CHEQUE DE CARTERA DEL CASHFLOW
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_
   Orden   : se puede correr en cualquier momento. NO depende de
             sql/echeqs_prechequeado.sql: aquel crea el maestro de la segunda
             sub-pestana y esto toca SOLO la primera. Sin este script, Cheques
             en Cartera funciona exactamente como hoy y la pantalla avisa que
             no se puede excluir, en vez de romperse.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La fila "Echeqs en cartera" del tablero trae TODO lo que en dbo.SBA14 esta
   en estado 'C' con fecha de hoy en adelante. Un cheque que ya se sabe que no
   se va a poder cobrar -el cliente aviso que no lo cubre, quedo judicializado,
   esta en gestion de cambio- entra igual al disponible y no hay donde decir
   que no.

   ----------------------------------------------------------------------------
   APLICA SOLO A CARTERA. PRECHEQUEADO NO SE TOCA.

   Son dos preguntas distintas y responderlas con el mismo tilde las confundiria:

     Cartera        "esta plata, ¿va a entrar?"           -> esto
     Prechequeado   "esta venta, ¿ya se cobro?"           -> el tilde de la
                                                             sub-pestana 2

   Un cheque puede estar en las dos pantallas -es correcto y da el numero justo,
   ver el encabezado de Class/Echeqs.php-. Excluirlo de cartera dice que ese
   importe NO va a entrar; no dice nada sobre si la venta que prepago hay que
   netearla o no. Por eso esta tabla es de ID_SBA14 y no se cruza en ningun lado
   con RO_T_CASHFLOW_ECHEQ_PRECHEQ.

   ----------------------------------------------------------------------------
   DONDE VA EL IMPORTE: SERIE PROPIA, COMO EN PROVEEDORES LOCALES

   Es el mismo criterio que explica el encabezado de
   sql/cashflow_prov_locales_excluir_factura.sql: la exclusion manual sale por
   una serie PROPIA y no reusando una que ya existia.

   Alla el motivo era que PAGOS_EXCLUIDOS pertenece a otro corte y el tilde no
   habria movido un peso de la fila del tablero. Aca el motivo es aun mas
   directo: ECHEQS tenia UNA SOLA SERIE, A_COBRAR, y era el universo entero.
   No habia ningun corte al que sumarse.

   Asi que el corte se crea ahora, con tres series y una sola division:

       A_COBRAR + A_COBRAR_EXCLUIDOS = A_COBRAR_TODO

   A_COBRAR sigue siendo el codigo que la fila del tablero ya tiene configurado
   -no hay que repuntar ninguna fila ni tocar Parametros- y pasa a significar
   "cartera cobrable". A_COBRAR_TODO es el universo, que es lo que A_COBRAR era
   hasta hoy.

   EL IMPORTE EXCLUIDO NO DESAPARECE: queda en su propia serie, visible y
   auditable, y EcheqsProvider avisa cuanto es y con que motivos, igual que
   ProveedoresProvider::avisarExcluidasAMano().

   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: la tabla nace
   vacia, asi que no hay ningun cheque excluido y A_COBRAR sigue valiendo lo
   mismo que A_COBRAR_TODO. Verificado contra la base al escribirlo: hoy son
   390 cheques por $2.056.009.561,46, y los tres totales dan ese numero.

   ----------------------------------------------------------------------------
   EL MOTIVO ES OBLIGATORIO

   Lo valida el back -Echeqs::excluirCheques()-, no la pantalla: el endpoint es
   alcanzable sin pasar por la grilla. Un cheque sacado del cashflow sin motivo
   no lo explica nadie tres meses despues, y este modulo esta construido sobre
   que nada desaparezca sin decir por que. Por eso la columna es NOT NULL: a
   diferencia de Proveedores Locales -donde el motivo cuelga de la fila de
   override, que existe tambien para facturas NO excluidas y por eso admite
   NULL-, aca una fila de esta tabla ES una exclusion. Una sin motivo no tiene
   sentido.

   ----------------------------------------------------------------------------
   NO HAY BAJAS FISICAS, Y EL HISTORIAL ES EL PUNTO

   Volver a incluir un cheque NO borra la fila: marca VIGENTE = 0 y le pone
   FECHA_BAJA. Excluirlo de nuevo inserta una fila nueva. Es el mismo criterio
   de RO_T_CASHFLOW_COBERTURA_APLIC y por la misma razon: con un UPDATE, un
   dedazo corregido a los cinco minutos y una decision que estuvo vigente tres
   semanas son indistinguibles despues del hecho, y la segunda es la que explica
   por que el disponible proyectado de la semana pasada era otro.

   POR ESO LA PK ES UN ID Y NO ID_SBA14: un cheque puede tener varias filas a lo
   largo del tiempo, y solo una vigente.

   SIN FK REAL CONTRA dbo.SBA14, a proposito y por el mismo motivo que
   RO_T_CASHFLOW_ECHEQ_PRECHEQ: es una tabla de Tango, y si un cheque se depura,
   una FK nuestra haria fallar la depuracion de un sistema que no es nuestro.
   Una exclusion huerfana es inofensiva: no aparece en ningun join.

   USUARIO VARCHAR(50) NULL porque todavia no hay login y se graba NULL. El
   metodo de guardado de PHP ya recibe $usuario.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LA TABLA DE EXCLUSIONES
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO (
        ID         INT IDENTITY(1,1) NOT NULL,
        /* La PK de dbo.SBA14. N_CHEQUE NO identifica un cheque: se repite entre
           bancos y entre anios. Es la misma trampa que documenta
           sql/echeqs_prechequeado.sql. */
        ID_SBA14   INT          NOT NULL,
        MOTIVO     VARCHAR(200) NOT NULL,
        VIGENTE    BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQEXC_VIGENTE DEFAULT (1),
        USUARIO    VARCHAR(50)  NULL,
        FECHA_ALTA DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQEXC_ALTA    DEFAULT (GETDATE()),
        /* Cuando se lo volvio a incluir. Con FECHA_ALTA sola no se puede
           distinguir una exclusion que se deshizo a los cinco minutos de una
           que estuvo vigente tres semanas. */
        FECHA_BAJA DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_EXCLUIDO PRIMARY KEY CLUSTERED (ID)
    );

    /* La consulta que corre en cada carga de la pestana y del tablero es "las
       vigentes, por cheque". Mismo criterio de indice que la cobertura. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQEXC_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO (VIGENTE, ID_SBA14);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UN SOLO CHEQUE EXCLUIDO VIGENTE A LA VEZ.

   El historial vive en las filas con VIGENTE = 0; las vigentes tienen que ser
   una por cheque, o el proveedor contaria el mismo importe dos veces en la
   serie de excluidos.

   Va como INDICE UNICO FILTRADO y no como constraint: un UNIQUE comun
   prohibiria tambien las filas historicas repetidas, que es justamente lo que
   esta tabla necesita guardar.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_ECHEQEXC_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQEXC_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO (ID_SBA14)
        WHERE VIGENTE = 1;

    PRINT 'Creado el indice unico de exclusiones vigentes.';
END
GO

/* ----------------------------------------------------------------------------
   3. Control: no puede haber una exclusion vigente sin motivo.

   La columna es NOT NULL, asi que lo unico que puede colarse es un texto en
   blanco cargado por fuera de la pantalla. No rompe nada -el importe sale del
   cashflow igual- pero nadie va a poder explicar por que.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_ECHEQ_EXCLUIDO
    WHERE VIGENTE = 1 AND LTRIM(RTRIM(MOTIVO)) = ''
)
BEGIN
    RAISERROR('ATENCION: hay cheques excluidos con el motivo en blanco. Revisalos: el importe sale del cashflow y nadie va a poder explicar por que.', 16, 1);
END
GO

PRINT 'Exclusion de cheques de cartera lista.';
GO
