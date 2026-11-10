/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: PARAMETROS DE LA PROYECCION
   ----------------------------------------------------------------------------
   Base   : central (la misma de la app de alquileres de administracion)
   Orden  : DESPUES de sql/cashflow_prov_locales.sql, que crea el maestro de
            proveedores (RO_T_CASHFLOW_PROV_LOCALES_CATEG). Sin el maestro las
            tablas se crean igual, pero la siembra del punto 6 no encuentra a
            quien sembrar y lo avisa.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_ALQ_GENERAL        el dia de vencimiento por defecto
     RO_T_CASHFLOW_ALQ_RAZON          las razones sociales DADAS DE ALTA como
                                      alquileres de locales, con su dia de
                                      vencimiento y los meses de diferimiento de
                                      los conceptos variables
     RO_T_CASHFLOW_ALQ_LOCAL          por local de la app de alquileres: razon
                                      social, frecuencia de ajuste, alicuotas,
                                      fecha de fin y venta manual
     RO_T_CASHFLOW_ALQ_EXTRA          los pagos extraordinarios que se repiten
                                      todos los años
     RO_T_CASHFLOW_ALQ_EXTRA_LOCAL    los locales de un extraordinario que NO
                                      es "todos los de la razon social"

   EL CASHFLOW NO ESCRIBE EN LA APP DE ALQUILERES. Las RO_T_*_ALQUILERES* son de
   administracion y aca solo se leen. Estas tablas guardan lo que la app no
   tiene y el cashflow necesita para saber cuanto se paga y cuando.

   NO HAY UNA LISTA PROPIA DE LOCALES: el universo es el de la app
   (RO_V_SUCURSALES_ALQUILERES). RO_T_CASHFLOW_ALQ_LOCAL solo completa datos, y
   un local sin fila no proyecta y figura como incompleto.

   UNA FILA VIGENTE POR CLAVE, CON HISTORIAL. Cambiar un dato es dar de baja la
   fila vigente (VIGENTE = 0, con quien y cuando) y dar de alta otra: cada
   cambio "rige desde ese momento" y el anterior queda para auditar. Lo
   garantiza un indice unico filtrado WHERE VIGENTE = 1. No hay bajas fisicas.

   UN DATO QUE FALTA ES NULL. Las columnas que hacen falta para proyectar
   (razon social, frecuencia, alicuotas) admiten NULL a proposito: la pantalla
   las muestra vacias y el local queda como incompleto, en vez de proyectar con
   un 0 que nadie cargo.

   COD_PROVEE lleva Latin1_General_BIN y el largo de CPA01, como el resto del
   circuito de proveedores: sin eso 'OGNUNE' y 'OGNUÑE' serian el mismo
   proveedor aca y dos en Tango.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes, y la siembra
   del punto 6 no repite filas. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. GENERALES: el dia de vencimiento por defecto

   Es el de las razones sociales que no tienen el suyo. NO SE SIEMBRA: sin
   cargarlo, esas razones sociales no proyectan y la pantalla lo avisa. Un 10
   sembrado aca seria un vencimiento que nadie decidio.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_GENERAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_GENERAL (
        ID              INT IDENTITY(1,1) NOT NULL,

        DIA_VENCIMIENTO TINYINT       NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQGEN_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQGEN_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQGEN_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_GENERAL PRIMARY KEY CLUSTERED (ID),

        /* Hasta el 28 para que exista en todos los meses: un 31 no tiene
           febrero, y correrlo al fin de mes seria una regla escondida. */
        CONSTRAINT CK_RO_T_CF_ALQGEN_DIA CHECK (DIA_VENCIMIENTO BETWEEN 1 AND 28)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_GENERAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_GENERAL ya existe: no se toca.';
GO

/* Una sola fila vigente: el indice va sobre una constante calculada. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_GENERAL', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQGEN_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_GENERAL'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQGEN_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_GENERAL (VIGENTE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQGEN_VIGENTE (una sola fila vigente).';
END
GO

/* ============================================================================
   2. RAZONES SOCIALES DADAS DE ALTA

   EL RUBRO NO DEFINE EL UNIVERSO: LO DEFINE ESTA TABLA. El rubro (la columna
   RUBRO del maestro, = ALQUILER) solo arma la lista del buscador desde la que
   se da de alta; dar de alta es una decision, porque no todo proveedor con ese
   rubro es el alquiler de un local (hoy aparecen Mercado Libre y Sploder).
   El servidor valida el alta contra ese universo.

   Una razon social esta DADA DE ALTA si tiene una fila VIGENTE = 1. Solo las
   dadas de alta tienen vencimiento y diferimiento, y solo ellas se eligen como
   razon social de un local o de un extraordinario. ESTO TAMBIEN DEFINE QUE
   FACTURA ES DE ALQUILER cuando lleguen las facturas de Tango: la de un
   proveedor dado de alta aca, no la de un rubro.

   Si despues el proveedor pierde el rubro en el maestro, SIGUE DE ALTA y la
   pantalla avisa: darlo de baja es una decision, no algo automatico.

   Cambiar el vencimiento o el diferimiento es baja de la fila vigente + alta
   de otra, como todo en este script. Darla de baja es VIGENTE = 0 sin fila
   nueva, y sus locales pasan a incompletos ("razon social dada de baja").

   MESES_DIFERIMIENTO arranca en 0 (se paga en el mismo mes de la venta). Los
   que difieren se cargan desde la pantalla, salvo los cuatro del punto 6.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_RAZON', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_RAZON (
        ID                  INT IDENTITY(1,1) NOT NULL,

        COD_PROVEE          VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        /* Copia, para que el historial siga siendo legible si el proveedor sale
           del maestro. La que se muestra es la del maestro. */
        RAZON_SOC           VARCHAR(100)  NULL,

        /* NULL = el dia general de RO_T_CASHFLOW_ALQ_GENERAL. */
        DIA_VENCIMIENTO     TINYINT       NULL,

        MESES_DIFERIMIENTO  TINYINT       NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQRAZ_DIFER DEFAULT (0),

        VIGENTE             BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQRAZ_VIGENTE DEFAULT (1),

        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQRAZ_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQRAZ_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_RAZON PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_ALQRAZ_COD
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0),
        CONSTRAINT CK_RO_T_CF_ALQRAZ_DIA
            CHECK (DIA_VENCIMIENTO IS NULL OR DIA_VENCIMIENTO BETWEEN 1 AND 28),
        /* El tope es de cordura, no de negocio: hoy el maximo es 2. */
        CONSTRAINT CK_RO_T_CF_ALQRAZ_DIFER
            CHECK (MESES_DIFERIMIENTO BETWEEN 0 AND 12)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_RAZON.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_RAZON ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_RAZON', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQRAZ_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_RAZON'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQRAZ_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_RAZON (COD_PROVEE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQRAZ_VIGENTE (una vigente por proveedor).';
END
GO

/* ============================================================================
   3. LOCALES

   NRO_SUCURS es el de la app (RO_T_DETALLE_ALQUILERES.NRO_SUCURS, INT).

   MES_ULT_AJUSTE ES UN MES DEL AÑO (1 a 12), no un año-mes: ancla el ciclo y
   alcanza, porque las frecuencias posibles (1, 2, 3, 6) dividen a 12. Con
   trimestral y septiembre, ajusta en diciembre, marzo, junio y septiembre de
   todos los años, y el dato no envejece. En mensual no hace falta.

   LAS ALICUOTAS SON PORCENTAJES (21 = 21 %), con dos decimales. El IVA puede
   ser mayor o menor que el general: en algunos locales se carga mas para
   incluir otros cargos. Percepcion y retencion son obligatorias para
   proyectar, y 0 es un valor valido: vacio y 0 no son lo mismo.

   FECHA_FIN: desde el mes siguiente el local deja de proyectar. Es para los
   locales habilitados que dejan de pagar alquiler; un local cerrado en Lakers
   (HABILITADO = 0) no proyecta aunque no la tenga.

   VENTA_MENSUAL_ESTIMADA es NETA (sin IVA), y solo la usan los locales nuevos,
   los que no tienen 12 meses de venta en la app.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL (
        ID                      INT IDENTITY(1,1) NOT NULL,

        NRO_SUCURS              INT           NOT NULL,

        /* Copia de la descripcion de la app, para leer el historial. */
        DESC_SUCURS             VARCHAR(100)  NULL,

        COD_PROVEE              VARCHAR(6)    COLLATE Latin1_General_BIN NULL,
        FRECUENCIA_MESES        TINYINT       NULL,
        MES_ULT_AJUSTE          TINYINT       NULL,
        PCT_IVA                 DECIMAL(7,2)  NULL,
        PCT_PERCEPCION          DECIMAL(7,2)  NULL,
        PCT_RETENCION           DECIMAL(7,2)  NULL,
        FECHA_FIN               DATE          NULL,
        VENTA_MENSUAL_ESTIMADA  DECIMAL(18,2) NULL,

        VIGENTE                 BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLOC_VIGENTE DEFAULT (1),

        USUARIO_ALTA            VARCHAR(50)   NULL,
        FECHA_ALTA              DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLOC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF           VARCHAR(50)   NULL,
        FECHA_MODIF             DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQLOC_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA            VARCHAR(50)   NULL,
        FECHA_BAJA              DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_LOCAL PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_ALQLOC_FREC
            CHECK (FRECUENCIA_MESES IS NULL OR FRECUENCIA_MESES IN (1, 2, 3, 6)),
        CONSTRAINT CK_RO_T_CF_ALQLOC_MES
            CHECK (MES_ULT_AJUSTE IS NULL OR MES_ULT_AJUSTE BETWEEN 1 AND 12),
        CONSTRAINT CK_RO_T_CF_ALQLOC_IVA
            CHECK (PCT_IVA IS NULL OR PCT_IVA >= 0),
        CONSTRAINT CK_RO_T_CF_ALQLOC_PERC
            CHECK (PCT_PERCEPCION IS NULL OR PCT_PERCEPCION >= 0),
        CONSTRAINT CK_RO_T_CF_ALQLOC_RET
            CHECK (PCT_RETENCION IS NULL OR PCT_RETENCION >= 0),
        CONSTRAINT CK_RO_T_CF_ALQLOC_VENTA
            CHECK (VENTA_MENSUAL_ESTIMADA IS NULL OR VENTA_MENSUAL_ESTIMADA > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_LOCAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_LOCAL ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQLOC_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQLOC_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_LOCAL (NRO_SUCURS)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQLOC_VIGENTE (una vigente por local).';
END
GO

/* ============================================================================
   4. PAGOS EXTRAORDINARIOS

   Ejemplo: en enero Cencosud cobra un 75 % adicional sobre el VMA (concepto 8)
   de diciembre. Importe por local = PCT x suma de los CONCEPTOS del local en el
   MES_BASE; cae en MES_BASE + MESES_DIFERIMIENTO. Se repite todos los años
   hasta que se da de baja.

   NO SE EDITA: se da de baja y se carga otro. Un extraordinario cambiado a
   mitad de año dejaria sin explicar los meses que ya se proyectaron con el
   anterior; con baja + alta el historial lo dice solo.

   CONCEPTOS es la lista de ID_CA de la app separados por coma ('8', '1,8').
   La valida el servidor contra AlquileresConceptos: solo conceptos que se
   proyectan. Es una lista corta y cerrada, y una tabla hija no agregaria nada.

   TODOS_LOCALES = 1: entran todos los locales de la razon social EN CADA
   MOMENTO, incluido uno que se le asigne despues. Con 0, los locales son los
   de RO_T_CASHFLOW_ALQ_EXTRA_LOCAL.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_EXTRA (
        ID                  INT IDENTITY(1,1) NOT NULL,

        COD_PROVEE          VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        TODOS_LOCALES       BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXT_TODOS DEFAULT (1),
        CONCEPTOS           VARCHAR(40)   NOT NULL,
        MES_BASE            TINYINT       NOT NULL,
        PCT                 DECIMAL(7,2)  NOT NULL,
        MESES_DIFERIMIENTO  TINYINT       NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXT_DIFER DEFAULT (0),
        OBSERVACION         VARCHAR(200)  NULL,

        VIGENTE             BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXT_VIGENTE DEFAULT (1),

        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXT_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXT_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_EXTRA PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_ALQEXT_COD
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0),
        CONSTRAINT CK_RO_T_CF_ALQEXT_CONC
            CHECK (LEN(LTRIM(RTRIM(CONCEPTOS))) > 0),
        CONSTRAINT CK_RO_T_CF_ALQEXT_MES
            CHECK (MES_BASE BETWEEN 1 AND 12),
        CONSTRAINT CK_RO_T_CF_ALQEXT_PCT
            CHECK (PCT > 0),
        CONSTRAINT CK_RO_T_CF_ALQEXT_DIFER
            CHECK (MESES_DIFERIMIENTO BETWEEN 0 AND 12)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_EXTRA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_EXTRA ya existe: no se toca.';
GO

/* ============================================================================
   5. LOCALES DE UN EXTRAORDINARIO

   Solo para los que no son "todos los locales". Las filas nacen con su
   extraordinario y no se editan: van con el ID del padre, que tampoco se edita.
   Llevan VIGENTE y auditoria igual, para que la baja de un extraordinario
   pueda dejar constancia en sus hijos.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL (
        ID              INT IDENTITY(1,1) NOT NULL,

        ID_EXTRA        INT           NOT NULL,
        NRO_SUCURS      INT           NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXL_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXL_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQEXL_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_EXTRA_LOCAL PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT FK_RO_T_CF_ALQEXL_EXTRA
            FOREIGN KEY (ID_EXTRA) REFERENCES dbo.RO_T_CASHFLOW_ALQ_EXTRA (ID)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_ALQEXL_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQEXL_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_EXTRA_LOCAL (ID_EXTRA, NRO_SUCURS)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_ALQEXL_VIGENTE (un local una vez por extraordinario).';
END
GO

/* ============================================================================
   6. LA SIEMBRA: CUATRO RAZONES SOCIALES DADAS DE ALTA

   Al 10/11/2026 los conceptos variables de IRSA, Arcos del Gourmet, Panamerican
   Mall y Cencosud se pagan 2 meses despues del mes de la venta. Se dan de alta
   con ese diferimiento POR CODIGO, que los identifica sin ambiguedad (Cencosud
   tiene otro codigo, OGEASY, que es de mantenimiento y no se toca). Las demas
   razones sociales se dan de alta desde la pantalla.

   Solo si el proveedor tiene RUBRO = ALQUILER en el maestro, que es la misma
   validacion que hace el servidor en un alta desde la pantalla. Al 10/11/2026
   los cuatro lo tienen. Si alguno lo perdio, no se siembra y se lista abajo.

   'SCRIPT' COMO USUARIO es lo que hace el paso reejecutable: un proveedor que
   ya tiene una fila -vigente, o sembrada y dada de baja- no se vuelve a
   sembrar. Asi, si alguien le cambia el diferimiento desde la pantalla, una
   segunda corrida no lo pisa.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_RAZON', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NOT NULL
BEGIN
    DECLARE @SIEMBRA TABLE (COD_PROVEE VARCHAR(6) COLLATE Latin1_General_BIN PRIMARY KEY);
    INSERT INTO @SIEMBRA (COD_PROVEE) VALUES ('SAPALA'), ('SAARCO'), ('SAPAMA'), ('SAUNIC');

    INSERT INTO dbo.RO_T_CASHFLOW_ALQ_RAZON
        (COD_PROVEE, RAZON_SOC, DIA_VENCIMIENTO, MESES_DIFERIMIENTO, VIGENTE,
         USUARIO_ALTA, USUARIO_MODIF)
    SELECT s.COD_PROVEE, MAX(m.NOMBRE), NULL, 2, 1, 'SCRIPT', 'SCRIPT'
    FROM @SIEMBRA s
    INNER JOIN dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG m
            ON m.COD_PROVEE = s.COD_PROVEE
           AND m.VIGENTE = 1
           AND UPPER(LTRIM(RTRIM(m.RUBRO))) COLLATE Modern_Spanish_CI_AI = 'ALQUILER'
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_ALQ_RAZON r
                      WHERE r.COD_PROVEE = s.COD_PROVEE
                        AND (r.VIGENTE = 1 OR r.USUARIO_ALTA = 'SCRIPT'))
    GROUP BY s.COD_PROVEE;

    PRINT 'Razones sociales dadas de alta con 2 meses de diferimiento: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + '.';

    /* Los que no se sembraron porque no tienen RUBRO = ALQUILER. */
    SELECT s.COD_PROVEE AS NO_SEMBRADO_SIN_RUBRO_ALQUILER
    FROM @SIEMBRA s
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG m
                      WHERE m.COD_PROVEE = s.COD_PROVEE
                        AND m.VIGENTE = 1
                        AND UPPER(LTRIM(RTRIM(m.RUBRO))) COLLATE Modern_Spanish_CI_AI = 'ALQUILER');
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NULL
    PRINT 'AVISO: no existe el maestro de proveedores (sql/cashflow_prov_locales.sql). No se sembro el diferimiento.';
GO

/* 7. Control: no inserta ni modifica nada. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_RAZON', 'U') IS NOT NULL
BEGIN
    SELECT COD_PROVEE, RAZON_SOC, DIA_VENCIMIENTO, MESES_DIFERIMIENTO,
           USUARIO_ALTA, FECHA_ALTA
    FROM dbo.RO_T_CASHFLOW_ALQ_RAZON
    WHERE VIGENTE = 1
    ORDER BY COD_PROVEE;
END
GO
