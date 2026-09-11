/* ============================================================================
   MODULO PROYECCION DE VENTAS Y COBRANZAS
   DDL de tablas + carga de semillas
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : ejecutar este script ANTES de sql/SJ_CASHFLOW_VENTAS_HIST.sql
   ----------------------------------------------------------------------------
   Todas las tablas llevan USUARIO VARCHAR(50) NULL. Todavia no hay login en la
   aplicacion, por lo que se graba NULL; los metodos de guardado de PHP ya
   reciben el parametro $usuario para cuando exista.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_VENTAS_HIST
   Historico de ventas agregado por mes / canal / tipo de comprobante.
   Lo puebla el SP SJ_CASHFLOW_VENTAS_HIST.
   La proyeccion usa unicamente TIPO_COMPROBANTE = 'FACTURA'.
   Los REMITO se guardan solo como bloque de control contra el tablero.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_HIST', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_HIST (
        ANIO             SMALLINT       NOT NULL,
        MES              TINYINT        NOT NULL,
        CANAL            VARCHAR(20)    NOT NULL,
        TIPO_COMPROBANTE VARCHAR(20)    NOT NULL,
        IMPORTE_NETO     DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_HIST_IMPORTE DEFAULT (0),
        CANTIDAD         FLOAT          NOT NULL CONSTRAINT DF_VENTAS_HIST_CANTIDAD DEFAULT (0),
        FECHA_CARGA      DATETIME       NOT NULL CONSTRAINT DF_VENTAS_HIST_FCARGA DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_HIST
            PRIMARY KEY CLUSTERED (ANIO, MES, CANAL, TIPO_COMPROBANTE)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_VENTAS_HIST_TIPO
        ON dbo.RO_T_CASHFLOW_VENTAS_HIST (TIPO_COMPROBANTE, ANIO, MES)
        INCLUDE (CANAL, IMPORTE_NETO, CANTIDAD);
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_VENTAS_INDICE
   Indice de variacion por mes del horizonte de proyeccion.
   Es UN indice por mes, no por canal.
       VentaNetaProyectada(M) = VentaNetaReal(M, anio anterior) * (1 + INDICE)
   INDICE = 0 significa "igual al mismo mes del anio anterior".
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_INDICE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_INDICE (
        ANIO         SMALLINT      NOT NULL,
        MES          TINYINT       NOT NULL,
        INDICE       DECIMAL(12,6) NOT NULL CONSTRAINT DF_VENTAS_INDICE_INDICE DEFAULT (0),
        FECHA_UPDATE DATETIME      NOT NULL CONSTRAINT DF_VENTAS_INDICE_FUPD DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50)   NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_INDICE PRIMARY KEY CLUSTERED (ANIO, MES)
    );
END
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_VENTAS_PARTIC
   Participacion de cada canal sobre la venta con IVA.
   Se guarda SIEMPRE el calculado junto al editado (mismo criterio _ORIG/_EDIT
   que usa RO_T_CASHFLOW_COMEX_CRONO_NAC).
       PORCENTAJE_CALC : el que calculo el motor desde el anio anterior
       PORCENTAJE_EDIT : el override manual; NULL = usar el calculado
   TIPO:
       'TRAMO28' -> participacion del tramo de 28 dias (ANIO/MES = mes de inicio
                    del tramo, sirve de ancla para la edicion del usuario)
       'MENSUAL' -> override por mes del horizonte
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_PARTIC', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_PARTIC (
        TIPO            VARCHAR(10)   NOT NULL,
        ANIO            SMALLINT      NOT NULL,
        MES             TINYINT       NOT NULL,
        CANAL           VARCHAR(20)   NOT NULL,
        PORCENTAJE_CALC DECIMAL(12,6) NOT NULL CONSTRAINT DF_VENTAS_PARTIC_CALC DEFAULT (0),
        PORCENTAJE_EDIT DECIMAL(12,6) NULL,
        FECHA_UPDATE    DATETIME      NOT NULL CONSTRAINT DF_VENTAS_PARTIC_FUPD DEFAULT (GETDATE()),
        USUARIO         VARCHAR(50)   NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_PARTIC
            PRIMARY KEY CLUSTERED (TIPO, ANIO, MES, CANAL),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_PARTIC_TIPO
            CHECK (TIPO IN ('TRAMO28', 'MENSUAL'))
    );
END
GO

/* ----------------------------------------------------------------------------
   4. RO_T_CASHFLOW_VENTAS_MIX
   Mix de medios de cobro y plazos de acreditacion por canal.
       Monto(canal, medio, dia) = VentaDiaria(canal, dia) * PORCENTAJE
       FechaAcreditacion        = dia + DIAS_ACREDITACION
                                  corrida al proximo dia bancario habil
   El PORCENTAJE de cada canal debe sumar 1 (100%).
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX (
        ID                INT IDENTITY(1,1) NOT NULL,
        CANAL             VARCHAR(20)   NOT NULL,
        MEDIO_PAGO        VARCHAR(30)   NOT NULL,
        PORCENTAJE        DECIMAL(12,6) NOT NULL CONSTRAINT DF_VENTAS_MIX_PORC DEFAULT (0),
        DIAS_ACREDITACION INT           NOT NULL CONSTRAINT DF_VENTAS_MIX_DIAS DEFAULT (0),
        ACTIVO            BIT           NOT NULL CONSTRAINT DF_VENTAS_MIX_ACTIVO DEFAULT (1),
        ORDEN             INT           NOT NULL CONSTRAINT DF_VENTAS_MIX_ORDEN DEFAULT (0),
        FECHA_UPDATE      DATETIME      NOT NULL CONSTRAINT DF_VENTAS_MIX_FUPD DEFAULT (GETDATE()),
        USUARIO           VARCHAR(50)   NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_MIX PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_VENTAS_MIX UNIQUE (CANAL, MEDIO_PAGO)
    );
END
GO

/* Semilla del mix de cobro: 9 filas, cada canal suma 100%. */
MERGE dbo.RO_T_CASHFLOW_VENTAS_MIX AS T
USING (VALUES
    ('LOCALES',     'CASH',          0.100000,  1, 1),
    ('LOCALES',     'TARJETA',       0.900000,  2, 2),
    ('LOCALES',     'GO CUOTAS',     0.000000, 10, 3),
    ('FRANQUICIAS', 'TRANSFERENCIA', 0.030000, 30, 4),
    ('FRANQUICIAS', 'ECHEQ',         0.970000, 40, 5),
    ('MAYORISTAS',  'CASH',          0.000000,  1, 6),
    ('MAYORISTAS',  'ECHEQ',         1.000000, 60, 7),
    ('ECOMMERCE',   'TARJETA',       1.000000,  2, 8),
    ('ECOMMERCE',   'GO CUOTAS',     0.000000, 10, 9)
) AS S (CANAL, MEDIO_PAGO, PORCENTAJE, DIAS_ACREDITACION, ORDEN)
    ON T.CANAL = S.CANAL AND T.MEDIO_PAGO = S.MEDIO_PAGO
WHEN NOT MATCHED BY TARGET THEN
    INSERT (CANAL, MEDIO_PAGO, PORCENTAJE, DIAS_ACREDITACION, ACTIVO, ORDEN)
    VALUES (S.CANAL, S.MEDIO_PAGO, S.PORCENTAJE, S.DIAS_ACREDITACION, 1, S.ORDEN);
GO

/* ----------------------------------------------------------------------------
   5. RO_T_CASHFLOW_PARAMETROS
   Clave/valor generico. Pensada para ir absorbiendo los parametros del resto
   de los modulos, por eso lleva GRUPO.
   Ningun valor de negocio debe estar hardcodeado en el codigo: todo sale de aca.
   ---------------------------------------------------------------------------- */
/* MODULO identifica a que pestana pertenece el parametro, para que en la
   pestana Parametros se vea agrupado por modulo y se entienda que afecta cada
   valor. GRUPO es la seccion dentro del modulo (GENERAL, RESPALDO, ...). */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PARAMETROS (
        CLAVE        VARCHAR(50)  NOT NULL,
        VALOR        VARCHAR(200) NOT NULL,
        TIPO_DATO    VARCHAR(20)  NOT NULL CONSTRAINT DF_PARAMETROS_TIPO DEFAULT ('DECIMAL'),
        DESCRIPCION  VARCHAR(200) NULL,
        MODULO       VARCHAR(30)  NOT NULL CONSTRAINT DF_PARAMETROS_MODULO DEFAULT ('VENTAS'),
        GRUPO        VARCHAR(50)  NULL,
        FECHA_UPDATE DATETIME     NOT NULL CONSTRAINT DF_PARAMETROS_FUPD DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50)  NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PARAMETROS PRIMARY KEY CLUSTERED (CLAVE)
    );
END
GO

/* Si la tabla ya existia sin MODULO (se creo con una version anterior de este
   script), se agrega y se marcan como VENTAS los parametros ya cargados, que
   son todos del modulo de ventas. Es idempotente. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PARAMETROS
        ADD MODULO VARCHAR(30) NOT NULL
            CONSTRAINT DF_PARAMETROS_MODULO DEFAULT ('VENTAS');
END
GO

MERGE dbo.RO_T_CASHFLOW_PARAMETROS AS T
USING (VALUES
    ('alicuota_iva',            '0.21',           'DECIMAL', 'Alicuota de IVA aplicada a la venta neta proyectada', 'VENTAS', 'GENERAL'),
    /* SIN USO. Los dias de pre-chequeado pasaron a ser POR CLIENTE, en
       RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE.DIAS_PRECHEQUEADO: un unico numero
       obligaba a elegir cual de todos los clientes quedaba bien calculado.
       La fila NO se borra -queda el valor que alguien haya cargado, por si
       hace falta reconstruir con que numero se proyecto- pero la pantalla ya
       no la muestra: la saca Parametros::RETIRADOS. Ver README-ventas.md. */
    ('dias_prechequeado',       '0',              'INT',     'SIN USO: los dias de pre-chequeado son por cliente', 'VENTAS', 'GENERAL'),
    ('horizonte_dias',          '28',             'INT',     'Cantidad de dias del tramo diario de la proyeccion', 'VENTAS', 'GENERAL'),
    ('horizonte_meses',         '12',             'INT',     'Cantidad de meses del horizonte de proyeccion', 'VENTAS', 'GENERAL'),
    /* Feriados de comercio: unicos dias del anio sin venta estimada.
       Formato MM-DD separado por coma. Se aplican a todos los anios. */
    ('feriados_comercio',       '12-25,01-01,09-26', 'CSV',  'Feriados de comercio (MM-DD) sin venta estimada', 'VENTAS', 'GENERAL'),
    /* Participacion fija de respaldo: se usa cuando el mes del anio anterior
       no tiene datos o su venta total es cero. Debe sumar 1. */
    ('respaldo_locales',        '0.410',          'DECIMAL', 'Participacion de respaldo - Locales', 'VENTAS', 'RESPALDO'),
    ('respaldo_franquicias',    '0.365',          'DECIMAL', 'Participacion de respaldo - Franquicias', 'VENTAS', 'RESPALDO'),
    ('respaldo_mayoristas',     '0.140',          'DECIMAL', 'Participacion de respaldo - Mayoristas', 'VENTAS', 'RESPALDO'),
    ('respaldo_ecommerce',      '0.085',          'DECIMAL', 'Participacion de respaldo - Ecommerce', 'VENTAS', 'RESPALDO')
) AS S (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
    ON T.CLAVE = S.CLAVE
WHEN NOT MATCHED BY TARGET THEN
    INSERT (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
    VALUES (S.CLAVE, S.VALOR, S.TIPO_DATO, S.DESCRIPCION, S.MODULO, S.GRUPO)
/* Reasigna el modulo si el parametro se habia cargado sin el. No toca VALOR:
   nunca pisa un valor que el usuario ya edito. */
WHEN MATCHED AND (T.MODULO IS NULL OR T.MODULO = '') THEN
    UPDATE SET T.MODULO = S.MODULO;
GO

/* ----------------------------------------------------------------------------
   6. RO_T_CASHFLOW_VENTAS_PRECHEQ
   Neteo de cheques adelantados (echeqs ya recibidos por ventas anteriores),
   para no duplicar cobranza.

   YA NO ES EL ORIGEN DE DATOS. El neteo sale hoy de la vista
   dbo.RO_V_CASHFLOW_VENTAS_PRECHEQ, que crea sql/echeqs_prechequeado.sql y que
   arma la sub-pestana Echeqs -> Venta Cobrada Anticipada. Esta tabla queda sin
   uso y sin lector: no se borra porque puede tener filas cargadas en algun
   ambiente, y borrarla se las llevaria puestas.

   La regla de reparto es la misma que estaba documentada aca y sigue vigente,
   solo que la aplica Ventas::getNeteoPrechequeado() sobre la vista:
     FECHA_VENTA_ESTIMADA = FECHA_CHEQUE - dias de pre-chequeado del CLIENTE
     El IMPORTE se resta de la cobranza proyectada de esa fecha (tramo diario)
     o de ese mes (tramo mensual).
   Los dias son POR CLIENTE, en
   RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE.DIAS_PRECHEQUEADO. El parametro global
   'dias_prechequeado' que siembra este script quedo sin uso; ver mas abajo.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_PRECHEQ', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_PRECHEQ (
        ID                    INT IDENTITY(1,1) NOT NULL,
        FECHA_CHEQUE          DATE          NOT NULL,
        FECHA_TEORICA_FACTURA DATE          NULL,
        IMPORTE               DECIMAL(18,4) NOT NULL CONSTRAINT DF_VENTAS_PRECHEQ_IMPORTE DEFAULT (0),
        CANAL                 VARCHAR(20)   NULL,
        ORIGEN                VARCHAR(50)   NULL,
        FECHA_CARGA           DATETIME      NOT NULL CONSTRAINT DF_VENTAS_PRECHEQ_FCARGA DEFAULT (GETDATE()),
        USUARIO               VARCHAR(50)   NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_PRECHEQ PRIMARY KEY CLUSTERED (ID)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_VENTAS_PRECHEQ_FECHA
        ON dbo.RO_T_CASHFLOW_VENTAS_PRECHEQ (FECHA_TEORICA_FACTURA)
        INCLUDE (IMPORTE, CANAL);
END
GO

/* ----------------------------------------------------------------------------
   7. RO_T_CASHFLOW_VENTAS_HIST_DIA
   Historico de ventas al grano de DIA / canal / tipo de comprobante.
   Lo puebla el SP RO_SP_CASHFLOW_VENTAS_HIST_DIA.

   Existe porque RO_T_CASHFLOW_VENTAS_HIST agrega al grano de MES, y con eso el
   mes en curso solo se puede comparar contra un mes completo del anio anterior:
   la comparacion sale siempre hundida porque enfrenta 6 dias contra 30. Este
   grano permite recortar el anio anterior a los mismos dias transcurridos.

   NO alimenta la proyeccion: la base de calculo sigue siendo la tabla mensual.
   Se usa solo en el bloque de tendencias de la sub-pestania Analisis de Ventas.

   TIPO_COMPROBANTE queda aunque hoy solo se carguen facturas: mantiene las dos
   tablas con la misma forma y deja que sumar remitos sea un cambio de SP y no
   una migracion.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_HIST_DIA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_HIST_DIA (
        FECHA            DATE           NOT NULL,
        CANAL            VARCHAR(20)    NOT NULL,
        TIPO_COMPROBANTE VARCHAR(20)    NOT NULL,
        IMPORTE_NETO     DECIMAL(18,4)  NOT NULL CONSTRAINT DF_VENTAS_HIST_DIA_IMPORTE DEFAULT (0),
        CANTIDAD         FLOAT          NOT NULL CONSTRAINT DF_VENTAS_HIST_DIA_CANTIDAD DEFAULT (0),
        FECHA_CARGA      DATETIME       NOT NULL CONSTRAINT DF_VENTAS_HIST_DIA_FCARGA DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_HIST_DIA
            PRIMARY KEY CLUSTERED (FECHA, CANAL, TIPO_COMPROBANTE)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_VENTAS_HIST_DIA_TIPO
        ON dbo.RO_T_CASHFLOW_VENTAS_HIST_DIA (TIPO_COMPROBANTE, FECHA)
        INCLUDE (CANAL, IMPORTE_NETO, CANTIDAD);
END
GO
