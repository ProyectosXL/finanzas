/* ============================================================================
   MODULO CASHFLOW - FLETEROS DE LOGISTICA LOCAL
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_parametros_generales.sql, que crea la
            inflacion mensual y el cronograma de pagos. Sin el, la tabla se crea
            igual y la pestana funciona: proyecta el valor base de cada fletero
            sin ningun ajuste trimestral y lo avisa.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   Una sola tabla: RO_T_CASHFLOW_LOGISTICA_FLETEROS. Quien es fletero, cuantas
   horas por mes trabaja y cuanto vale su hora.

   NO CREA NINGUNA FILA DEL TABLERO. La fila LOGISTICA ya existe en
   RO_T_CASHFLOW_CONF_FILA -seccion COSTOS_DIRECTOS, apuntada a LOGISTICA /
   PAGOS- desde sql/cashflow_estructura.sql. Lo unico que cambia es que a partir
   de ahora el proveedor existe y la fila deja de estar en cero.

   ----------------------------------------------------------------------------
   NO SE PRECARGA NINGUN FLETERO, Y ES A PROPOSITO

   Los codigos van a ser OGGIBA, OGSEBA, OGDARI y OGTAPI, y los cuatro existen
   en CPA01. Sembrarlos desde aca igual seria un error: LAS HORAS Y EL VALOR
   HORA NO LOS SABE ESTE SCRIPT. Un fletero sembrado sin esos dos numeros no
   proyecta nada -queda en null y avisando- asi que la unica diferencia seria
   que la pantalla nace con cuatro filas vacias en vez de con ninguna, y con el
   riesgo de que alguien lea esas filas como "ya esta configurado".

   Se dan de alta desde Parametros -> Logistica, buscando en CPA01.

   ----------------------------------------------------------------------------
   TAMPOCO SE EXCLUYE A NADIE DE PROVEEDORES LOCALES

   Estos cuatro proveedores tienen deuda en Cuentas a Pagar Locales, asi que
   mientras esten en las dos pestanas su pago se cuenta dos veces. La exclusion
   YA EXISTE y es manual: RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO, que se carga desde
   el maestro de Proveedores Locales (ver sql/cashflow_prov_exclusion_modulo.sql
   y README-proveedores-locales.md).

   Este script NO la hace, y es deliberado: excluir a un proveedor saca su deuda
   REAL Y YA FACTURADA del tablero, y esa es una decision de quien mira las
   cuentas a pagar, no un efecto de dar de alta un fletero. La pestana Logistica
   Local lo avisa cuando detecta un fletero activo que no esta excluido.

   ----------------------------------------------------------------------------
   LA COLLATION NO ES UN DETALLE

   COD_PROVEE declara Latin1_General_BIN, la MISMA que CPA01.COD_PROVEE. Sin
   declararla la columna toma la de la base -Modern_Spanish_CI_AI, acento
   insensible- y 'OGNUNE' y 'OGNUÑE' serian el mismo valor aca y dos proveedores
   distintos en Tango. Hay 27 codigos con caracteres no ASCII, asi que no es
   hipotetico. Mismo criterio que RO_T_CASHFLOW_PROV_LOCALES_CATEG.

   ----------------------------------------------------------------------------
   LOS TRES NUMEROS SON NULL-ABLES, Y ESO ES LA REGLA DEL MODULO

   HORAS_MES, VALOR_HORA_BASE y MES_BASE nacen en NULL. Un fletero al que le
   falta cualquiera de los tres NO SE PROYECTA y la pestana lo avisa: no se pone
   cero. Un cero se leeria como "este mes no se le paga nada", que es una
   afirmacion que nadie hizo. Es el mismo criterio de Cotizacion y de
   DolarFuturo.

   Por eso tampoco hay DEFAULT en ninguno de los tres: un DEFAULT 0 convertiria
   la falta de dato en un dato, que es exactamente lo que se quiere evitar.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS

   ACTIVO = 0 en vez de DELETE. Un fletero que dejo de trabajar tiene historia
   -las horas y el valor hora con los que se proyecto- y borrarlo la tira. La
   pantalla de Parametros muestra los inactivos para poder reactivarlos; la
   planilla y el tablero sólo miran los activos.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La pestana Logistica Local NO falla: avisa que falta este script y muestra la
   planilla vacia. La fila LOGISTICA del tablero va en CERO -que es lo que va
   hoy- y el proveedor lo dice con su propio aviso, asi que el cuadro no informa
   de menos en silencio. La seccion de alta de Parametros se dibuja apagada
   nombrando este archivo.

   ES REEJECUTABLE: la tabla pregunta por OBJECT_ID y no se siembra nada. La
   segunda corrida no modifica ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda: CPA01 es contra lo que se valida cada codigo.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.CPA01', 'U') IS NULL
BEGIN
    PRINT 'AVISO: no existe dbo.CPA01. La tabla se crea igual, pero el alta de fleteros no '
        + 'va a poder validar ningun codigo ni traer ningun nombre: la pantalla lo avisa.';
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_LOGISTICA_FLETEROS
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS (
        /* La clave es el codigo de Tango. Ver la nota de la collation. */
        COD_PROVEE      VARCHAR(6) COLLATE Latin1_General_BIN NOT NULL,

        /* El nombre TAL COMO ESTABA EN CPA01 AL DAR DE ALTA. Es una copia, no
           la fuente: la pantalla muestra el de CPA01 cuando puede leerlo. Se
           guarda para que la fila siga siendo legible si Tango no responde -un
           codigo suelto no le dice nada a nadie- y para poder ver que un
           proveedor cambio de nombre. NO SE EDITA desde el cashflow. */
        NOMBRE          VARCHAR(120)  NULL,

        /* Horas por mes, CONSTANTES en todos los meses. No hay horas por mes
           calendario: lo que se pacta es un regimen mensual, y una grilla de
           doce celdas por fletero pediria mantener doce numeros para describir
           uno solo. */
        HORAS_MES       DECIMAL(10,2) NULL,

        /* El valor hora YA AJUSTADO que rige desde MES_BASE. Cuatro decimales
           porque los ajustes trimestrales se encadenan y el redondeo del primero
           se arrastra a todos los siguientes. */
        VALOR_HORA_BASE DECIMAL(18,4) NULL,

        /* Desde que mes rige ese valor, 'YYYY-MM'. NO es la fecha de alta: es el
           mes del ultimo ajuste pactado, y es lo que fija el calendario de los
           siguientes (MES_BASE + 3, + 6, + 9...). CHAR(7) y no DATE por el mismo
           motivo que los demas meses del modulo: es un mes, no un dia. */
        MES_BASE        CHAR(7)       NULL,

        ACTIVO          BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGFLE_ACTIVO DEFAULT (1),
        USUARIO         VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGFLE_ALTA DEFAULT (GETDATE()),
        FECHA_UPDATE    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGFLE_UPD  DEFAULT (GETDATE()),
        /* Cuando se dio de baja. Con FECHA_UPDATE sola no se distingue una baja
           de una correccion del valor hora. */
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_LOGISTICA_FLETEROS PRIMARY KEY CLUSTERED (COD_PROVEE),

        /* Las horas y el valor hora pueden FALTAR, pero no pueden ser negativos
           ni cero: un cero es un dato y significa "no se le paga", que se
           expresa dando de baja al fletero y no cargando un cero que la
           planilla no sabria distinguir de un olvido. */
        CONSTRAINT CK_RO_T_CF_LOGFLE_HORAS CHECK (HORAS_MES IS NULL OR HORAS_MES > 0),
        CONSTRAINT CK_RO_T_CF_LOGFLE_VALOR CHECK (VALOR_HORA_BASE IS NULL OR VALOR_HORA_BASE > 0),

        /* El formato del mes va en la base y no solo en el PHP: el endpoint es
           alcanzable sin pasar por la pantalla. Mismo criterio que el CHECK de
           MODALIDAD en RO_T_CASHFLOW_INFLACION_MES. */
        CONSTRAINT CK_RO_T_CF_LOGFLE_MESBASE
            CHECK (MES_BASE IS NULL OR MES_BASE LIKE '[12][0-9][0-9][0-9]-[01][0-9]')
    );

    /* La consulta que corre en cada carga de la planilla y del tablero es "los
       activos". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_LOGFLE_ACTIVO
        ON dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS (ACTIVO)
        INCLUDE (COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE);

    PRINT 'RO_T_CASHFLOW_LOGISTICA_FLETEROS creada. No se sembro ningun fletero: '
        + 'las horas y el valor hora los carga el usuario desde Parametros -> Logistica.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_LOGISTICA_FLETEROS ya existia.';
GO

/* ============================================================================
   2. CONTROL: COMO QUEDO

   Los cuatro codigos que van a ser los primeros fleteros, para poder
   verificar que existen en CPA01 ANTES de darlos de alta desde la pantalla.
   No se insertan: solo se listan.
   ============================================================================ */
IF OBJECT_ID('dbo.CPA01', 'U') IS NOT NULL
BEGIN
    SELECT COD_PROVEE, NOM_PROVEE
    FROM dbo.CPA01
    WHERE COD_PROVEE IN ('OGGIBA', 'OGSEBA', 'OGDARI', 'OGTAPI')
    ORDER BY COD_PROVEE;
END
GO

DECLARE @n INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS);
DECLARE @activos INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS WHERE ACTIVO = 1);

PRINT 'Fleteros cargados: ' + CAST(@n AS VARCHAR(10)) + ' (' + CAST(@activos AS VARCHAR(10))
    + ' activos).';

/* Los que estan activos pero sin los datos para proyectar. NO es un error de
   este script: es el estado normal de un fletero recien dado de alta. Se
   imprime para que se vea desde la consola lo mismo que avisa la pestana. */
DECLARE @incompletos INT = (
    SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS
    WHERE ACTIVO = 1
      AND (HORAS_MES IS NULL OR VALOR_HORA_BASE IS NULL OR MES_BASE IS NULL));

IF @incompletos > 0
    PRINT 'AVISO: ' + CAST(@incompletos AS VARCHAR(10)) + ' fletero(s) activos sin horas, '
        + 'sin valor hora o sin mes base. NO se proyectan y la pestana los avisa: no se '
        + 'los cuenta como cero.';
GO
