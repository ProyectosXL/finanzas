/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: LA IMPUTACION DE FACTURAS POR RAZON SOCIAL
   (script 60)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres_facturas.sql (script 53).
   ----------------------------------------------------------------------------
   QUE HACE

     1. Los TIPOS DE RETENCION de Parametros -> Alquileres (Ganancias, IIBB...).
     2. BORRA FISICAMENTE las imputaciones del modelo de renglones (script 53)
        y sus cinco tablas, y crea las del modelo por razon social. En UNA
        transaccion: o queda el modelo nuevo entero, o queda todo como estaba.

     RO_T_CASHFLOW_ALQ_TIPO_RETENCION   los tipos de retencion
     RO_T_CASHFLOW_ALQ_IMPUTACION_RS    la cabecera: razon social, mes que
                                        cubre, todos los locales o algunos,
                                        observacion, totales
     RO_T_CASHFLOW_ALQ_IMPRS_CUOTA      las cuotas imputadas, con el pendiente
                                        al imputar, su parte de las
                                        retenciones y si ya se pago
     RO_T_CASHFLOW_ALQ_IMPRS_LOCAL      los locales que cubre
     RO_T_CASHFLOW_ALQ_IMPRS_RETENCION  las retenciones, por tipo

   RO_T_CASHFLOW_ALQ_FACTURA_VTO (el vencimiento a considerar, del script 53)
   NO SE TOCA.

   ----------------------------------------------------------------------------
   POR QUE CAMBIA EL MODELO

   Antes una imputacion se repartia en RENGLONES de conceptos x locales x mes,
   y la suma de los renglones tenia que dar el pendiente. En la practica una
   factura de alquiler es de UNA razon social, cubre UN mes de sus locales y
   paga todo lo que ella factura ahi: abrirla en conceptos era trabajo y no
   cambiaba lo que se paga. Ahora una imputacion PISA TODO LO PROYECTADO DE ESA
   RAZON SOCIAL, EN ESOS LOCALES, EN ESE MES DE PAGO (fijos, fondo, expensas,
   variables y extraordinarios: lo que ella factura, ver el script 62), y manda
   lo real. Ver cashflow/Class/AlquileresImputacion.php.

   ----------------------------------------------------------------------------
   POR QUE SE BORRAN LAS IMPUTACIONES VIEJAS (y no se migran)

   Es la unica excepcion a "no hay bajas fisicas" del modulo, autorizada para
   este cambio: un renglon de conceptos no se puede traducir a "toda la razon
   social en esos locales" sin decidir por el usuario. Al 20/11/2026 habia dos
   vigentes (SACORR A0000200000467 y SAARCO A0001300017366, las dos pagadas y
   de noviembre) y dos dadas de baja: se reimputan a mano con la pantalla nueva.
   Hasta que se reimputen, la proyeccion de noviembre de Gurruchaga y Distrito
   Arcos vuelve al flujo. Antes de borrar se imprime cuantas filas tenia cada
   tabla.

   ----------------------------------------------------------------------------
   UNA CUOTA SE IMPUTA UNA SOLA VEZ: el indice unico filtrado de IMPRS_CUOTA
   (VIGENTE = 1) lo garantiza contra dos pantallas guardando a la vez. Para
   corregir una imputacion se deshace (VIGENTE = 0, con quien y cuando) y se
   arma de nuevo: no se edita. Deshacer no borra: la imputacion deshecha
   explica por que el tablero de ayer decia otra cosa.

   DOS IMPUTACIONES VIGENTES NO PISAN LO MISMO: misma razon social, mismo mes y
   algun local en comun. No se puede expresar con un indice (es una
   interseccion de listas); lo valida el PHP, y al guardar lo vuelve a mirar
   dentro de la transaccion con las cabeceras bloqueadas.

   LOS LOCALES SE GUARDAN COMO ERAN AL IMPUTAR, tambien con "todos los locales":
   TODOS_LOCALES queda para mostrar como se armo. La factura es un hecho de ese
   momento, y un local que cambie de razon social despues no la cambia.

   SIN ESTE SCRIPT la sub-pestaña Facturas carga, no deja imputar y avisa en
   critico nombrandolo. Las imputaciones viejas, si estan, no se leen: la
   proyeccion no se reemplaza con nada, y las facturas siguen sin sumar hasta
   que se imputen.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID, sys.indexes o si la fila ya
   existe. Si las tablas viejas ya no estan, no hace nada con ellas.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS TIPOS DE RETENCION

   Una imputacion carga sus retenciones POR TIPO (una linea por tipo). Los
   tipos se dan de alta, se renombran y se dan de baja en Parametros ->
   Alquileres -> Tipos de retencion.

   EL NOMBRE ES UNICO ENTRE LOS VIGENTES SIN DISTINGUIR MAYUSCULAS NI ACENTOS:
   por eso la columna va con Latin1_General_CI_AI y el indice unico filtrado
   la usa. "IIBB CABA" e "iibb caba" son el mismo tipo.

   RENOMBRAR ES DAR DE BAJA Y DAR DE ALTA OTRA FILA, como el resto de
   Parametros: una imputacion vieja guarda el ID del tipo con el que se cargo y
   sigue mostrando ese nombre. UN TIPO DADO DE BAJA no se ofrece para imputar,
   pero las imputaciones que lo usan lo siguen mostrando.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (
        ID              INT IDENTITY(1,1) NOT NULL,
        NOMBRE          VARCHAR(60)  COLLATE Latin1_General_CI_AI NOT NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_VIGENTE DEFAULT (1),
        /* Si se dio de baja por un cambio de nombre, la fila que la reemplaza. */
        ID_REEMPLAZO    INT          NULL,

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQTRET_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_TIPO_RETENCION PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_ALQTRET_NOMBRE CHECK (LEN(LTRIM(RTRIM(NOMBRE))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQTRET_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQTRET_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (NOMBRE)
        WHERE VIGENTE = 1;
GO

/* La siembra: solo los que no existen vigentes. No pisa un nombre editado ni
   vuelve a dar de alta uno que alguien dio de baja a proposito. */
DECLARE @sembrar TABLE (NOMBRE VARCHAR(60) COLLATE Latin1_General_CI_AI);
INSERT INTO @sembrar (NOMBRE) VALUES ('Ganancias'), ('IIBB Santa Fe'), ('IIBB CABA'), ('IIBB Bs As');

INSERT INTO dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (NOMBRE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
SELECT S.NOMBRE, 1, 'script 60', 'script 60'
FROM @sembrar S
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION T WHERE T.NOMBRE = S.NOMBRE);

PRINT CAST(@@ROWCOUNT AS VARCHAR(10)) + ' tipo(s) de retención sembrado(s).';
GO

/* ----------------------------------------------------------------------------
   2. EL MODELO VIEJO SE BORRA Y SE CREA EL NUEVO, EN UNA TRANSACCION

   Todo va con SQL dinamico (EXEC / sp_executesql): en un mismo lote, una
   sentencia que nombra una tabla creada o borrada unas lineas antes no se
   puede compilar de antemano, y asi cada una se compila cuando le toca.
   XACT_ABORT + TRY/CATCH: ante cualquier error se deshace todo y se relanza.
   ---------------------------------------------------------------------------- */
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @tabla SYSNAME, @n INT, @sql NVARCHAR(MAX);
    DECLARE @viejas TABLE (ORDEN INT, NOMBRE SYSNAME);

    /* En el orden de las FK: primero las hijas. */
    INSERT INTO @viejas (ORDEN, NOMBRE) VALUES
        (1, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL'),
        (2, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO'),
        (3, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON'),
        (4, 'RO_T_CASHFLOW_ALQ_IMP_FACTURA'),
        (5, 'RO_T_CASHFLOW_ALQ_IMPUTACION');

    DECLARE viejas CURSOR LOCAL FAST_FORWARD FOR SELECT NOMBRE FROM @viejas ORDER BY ORDEN;
    OPEN viejas;
    FETCH NEXT FROM viejas INTO @tabla;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF OBJECT_ID('dbo.' + @tabla, 'U') IS NOT NULL
        BEGIN
            SET @sql = N'SELECT @n = COUNT(*) FROM dbo.' + QUOTENAME(@tabla);
            EXEC sp_executesql @sql, N'@n INT OUTPUT', @n = @n OUTPUT;
            PRINT 'dbo.' + @tabla + ': tenía ' + CAST(@n AS VARCHAR(10)) + ' fila(s). Se borra.';

            SET @sql = N'DROP TABLE dbo.' + QUOTENAME(@tabla);
            EXEC sp_executesql @sql;
        END
        ELSE
            PRINT 'dbo.' + @tabla + ' ya no existe: nada que borrar.';

        FETCH NEXT FROM viejas INTO @tabla;
    END

    CLOSE viejas;
    DEALLOCATE viejas;

    /* LA CABECERA. ES LA QUE MIRA AlquileresImputaciones::tablaCreada(): sin
       ella la sub-pestaña Facturas no deja imputar y nombra este script. */
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS', 'U') IS NULL
    BEGIN
        EXEC (N'CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS (
            ID                INT IDENTITY(1,1) NOT NULL,

            /* La razon social de TODAS las cuotas: una imputacion es de una sola. */
            COD_PROVEE        VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
            /* El MES DE PAGO cuya proyeccion reemplaza, YYYY-MM. */
            MES_CUBRE         CHAR(7)       NOT NULL,
            /* Si se armo con todos los locales de la razon social. Solo para
               mostrar: los locales que valen son los de IMPRS_LOCAL. */
            TODOS_LOCALES     BIT           NOT NULL,
            OBSERVACION       VARCHAR(300)  NULL,

            /* Con IVA y percepciones, tal como vienen las facturas. El total a
               pagar es TOTAL_FACTURAS - TOTAL_RETENCIONES. */
            TOTAL_FACTURAS    DECIMAL(18,2) NOT NULL,
            TOTAL_RETENCIONES DECIMAL(18,2) NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRS_RET DEFAULT (0),

            VIGENTE           BIT           NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRS_VIGENTE DEFAULT (1),
            MOTIVO_BAJA       VARCHAR(200)  NULL,

            USUARIO_ALTA      VARCHAR(50)   NULL,
            FECHA_ALTA        DATETIME      NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRS_ALTA DEFAULT (GETDATE()),
            USUARIO_MODIF     VARCHAR(50)   NULL,
            FECHA_MODIF       DATETIME      NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRS_MODIF DEFAULT (GETDATE()),
            USUARIO_BAJA      VARCHAR(50)   NULL,
            FECHA_BAJA        DATETIME      NULL,

            CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMPUTACION_RS PRIMARY KEY CLUSTERED (ID),
            CONSTRAINT CK_RO_T_CF_ALQIRS_COD CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0),
            CONSTRAINT CK_RO_T_CF_ALQIRS_MES CHECK (MES_CUBRE LIKE ''[12][0-9][0-9][0-9]-[01][0-9]''),
            CONSTRAINT CK_RO_T_CF_ALQIRS_TOTALES
                CHECK (TOTAL_FACTURAS > 0 AND TOTAL_RETENCIONES >= 0 AND TOTAL_RETENCIONES <= TOTAL_FACTURAS)
        )');

        EXEC (N'CREATE NONCLUSTERED INDEX IX_RO_T_CF_ALQIRS_RAZON_MES
            ON dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS (COD_PROVEE, MES_CUBRE) WHERE VIGENTE = 1');

        PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS.';
    END
    ELSE
        PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS ya existe: no se toca.';

    /* LAS CUOTAS. */
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA', 'U') IS NULL
    BEGIN
        EXEC (N'CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA (
            ID                INT IDENTITY(1,1) NOT NULL,
            ID_IMPUTACION     INT           NOT NULL,

            /* La cuota, con los tipos y la collation de CPA04. */
            COD_PROVEE        VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
            T_COMP            VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
            N_COMP            VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,
            FECHA_VTO_TANGO   DATE          NOT NULL,

            /* EL PENDIENTE AL IMPUTAR (o el importe de la cuota, si ya estaba
               pagada en Tango): si Tango lo cambia sin cancelarla se paga el
               nuevo menos su retencion, y se avisa. */
            PENDIENTE         DECIMAL(18,2) NOT NULL,
            /* SU PARTE DE LAS RETENCIONES: el total de retenciones repartido
               entre las cuotas por su pendiente, por resto mayor al centavo. */
            RETENCION         DECIMAL(18,2) NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_RET DEFAULT (0),
            /* Ya estaba cancelada en Tango al imputarla (Pagada, sin imputar). */
            PAGADA_AL_IMPUTAR BIT           NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_PAI DEFAULT (0),

            /* Copia del VIGENTE de la cabecera, en la misma transaccion: es lo
               que permite el indice unico filtrado. */
            VIGENTE           BIT           NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_VIGENTE DEFAULT (1),

            ESTADO            VARCHAR(10)   NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_ESTADO DEFAULT (''PENDIENTE''),
            FECHA_PAGADA      DATE          NULL,
            FECHA_DETECTADA   DATETIME      NULL,

            USUARIO_ALTA      VARCHAR(50)   NULL,
            FECHA_ALTA        DATETIME      NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_ALTA DEFAULT (GETDATE()),
            USUARIO_MODIF     VARCHAR(50)   NULL,
            FECHA_MODIF       DATETIME      NOT NULL
                CONSTRAINT DF_RO_T_CF_ALQIRC_MODIF DEFAULT (GETDATE()),
            USUARIO_BAJA      VARCHAR(50)   NULL,
            FECHA_BAJA        DATETIME      NULL,

            CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMPRS_CUOTA PRIMARY KEY CLUSTERED (ID),
            CONSTRAINT FK_RO_T_CF_ALQIRC_IMP FOREIGN KEY (ID_IMPUTACION)
                REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS (ID),
            CONSTRAINT CK_RO_T_CF_ALQIRC_ESTADO CHECK (ESTADO IN (''PENDIENTE'', ''PAGADA'')),
            CONSTRAINT CK_RO_T_CF_ALQIRC_IMPORTES CHECK (PENDIENTE > 0 AND RETENCION >= 0 AND RETENCION <= PENDIENTE)
        )');

        EXEC (N'CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQIRC_VIGENTE
            ON dbo.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO) WHERE VIGENTE = 1');

        PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA.';
    END
    ELSE
        PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA ya existe: no se toca.';

    /* LOS LOCALES (NRO_SUCURS de la app de alquileres). */
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPRS_LOCAL', 'U') IS NULL
    BEGIN
        EXEC (N'CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMPRS_LOCAL (
            ID_IMPUTACION     INT           NOT NULL,
            NRO_LOCAL         INT           NOT NULL,

            CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMPRS_LOCAL PRIMARY KEY CLUSTERED (ID_IMPUTACION, NRO_LOCAL),
            CONSTRAINT FK_RO_T_CF_ALQIRL_IMP FOREIGN KEY (ID_IMPUTACION)
                REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS (ID)
        )');

        PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMPRS_LOCAL.';
    END
    ELSE
        PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMPRS_LOCAL ya existe: no se toca.';

    /* LAS RETENCIONES, por tipo. LA CLAVE (imputacion, tipo) ES LA QUE IMPIDE
       REPETIR UN TIPO en la misma imputacion. */
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPRS_RETENCION', 'U') IS NULL
    BEGIN
        EXEC (N'CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMPRS_RETENCION (
            ID_IMPUTACION     INT           NOT NULL,
            ID_TIPO           INT           NOT NULL,
            IMPORTE           DECIMAL(18,2) NOT NULL,

            CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMPRS_RETENCION PRIMARY KEY CLUSTERED (ID_IMPUTACION, ID_TIPO),
            CONSTRAINT FK_RO_T_CF_ALQIRR_IMP FOREIGN KEY (ID_IMPUTACION)
                REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMPUTACION_RS (ID),
            CONSTRAINT FK_RO_T_CF_ALQIRR_TIPO FOREIGN KEY (ID_TIPO)
                REFERENCES dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION (ID),
            CONSTRAINT CK_RO_T_CF_ALQIRR_IMPORTE CHECK (IMPORTE > 0)
        )');

        PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMPRS_RETENCION.';
    END
    ELSE
        PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMPRS_RETENCION ya existe: no se toca.';

    COMMIT TRANSACTION;
    PRINT 'Imputación por razón social lista. Las imputaciones viejas ya no están: reimputá sus facturas en '
        + 'Alquileres › Facturas.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    PRINT 'ERROR: no se cambió nada. ' + ERROR_MESSAGE();
    THROW;
END CATCH
GO
