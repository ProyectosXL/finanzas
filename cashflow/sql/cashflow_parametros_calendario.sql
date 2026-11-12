/* ============================================================================
   MODULO CASHFLOW - SUB-PESTANA "CALENDARIO" DE PARAMETROS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_parametros_generales.sql, que es el que
            dejo en GENERALES los parametros que este script mueve, y DESPUES
            de sql/migracion_parametros_modulo.sql (la columna MODULO). Si
            MODULO no existe, este script NO migra nada y lo dice.
            Independiente de sql/cashflow_calendario_permisos.sql, que va a apps.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   1. RO_T_CASHFLOW_CALENDARIO_AJUSTE: los feriados bancarios que se agregan o
      se quitan a mano sobre RO_T_CALENDARIO, con su historial.
   2. MIGRA al modulo 'CALENDARIO' los parametros de fechas: 'horizonte_dias',
      'horizonte_meses', 'feriados_comercio' y los 'cronograma_*'. NO TOCA
      NINGUN VALOR: solo cambia en que sub-pestana se dibujan (y con eso, que
      permiso pide guardarlos: ver Parametros::subPestanaDe()).

   ----------------------------------------------------------------------------
   POR QUE UNA TABLA DE AJUSTES Y NO ESCRIBIR EN RO_T_CALENDARIO

   RO_T_CALENDARIO vive en XL-APPS / POWER_BI_CONTROL (conexion 'power'), la
   mantiene otro proceso y la leen otros sistemas. Escribir ahi un feriado del
   cashflow cambiaria el calendario de todos, y la proxima carga del proceso
   dueno lo pisaria sin aviso.

   Por eso los ajustes viven aca, en central, y se aplican al leer:
   Ventas::getDiasHabiles() = la lectura de RO_T_CALENDARIO (sin cambios, sigue
   siendo la unica) + CalendarioAjustes::aplicar(). Todo lo que corre una
   fecha al dia habil -cronograma de pagos, Ventas, Echeqs, Tarjetas, Saldos,
   la marca del eje- ve el ajuste sin enterarse.

   UNA FILA POR FECHA, CON EL VALOR QUE VALE: DIA_LABORAL 0 es un feriado
   agregado, 1 es un feriado del calendario bancario que se pasa a habil. Solo
   de lunes a viernes, y solo si es distinto de lo que dice RO_T_CALENDARIO
   (lo valida CalendarioAjustes::validar(): un ajuste igual al calendario no
   ajusta nada y confunde).

   SIN BAJAS FISICAS, igual que RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT: quitar un
   ajuste marca VIGENTE = 0 y deja la fila. Con un DELETE, "esta fecha nunca se
   toco" y "se toco y se volvio atras" son indistinguibles despues del hecho.

   ----------------------------------------------------------------------------
   POR QUE LOS PARAMETROS DE FECHAS SE VAN DE GENERALES

   El horizonte, los feriados de comercio y el cronograma de pagos son
   decisiones sobre FECHAS, y la sub-pestana Calendario es donde se administran
   todas: el eje, que dias no se vende, que dias no opera el banco y que dia se
   paga cada concepto. En Generales quedan la alicuota, la inflacion, Gastos
   Locales y las cotizaciones.

   NINGUN CALCULO SE ENTERA DE LA MIGRACION, por el mismo motivo que en
   sql/cashflow_parametros_generales.sql: el unico lugar que pide parametros
   POR MODULO es Parametros::getModulosConDatos(), que arma la pestana. Las
   formulas los leen por CLAVE con getParametrosMap().

   EL GRUPO NO CAMBIA: los tres primeros siguen en 'GENERAL' -la seccion
   'generales' de Calendario pide ese grupo- y los 'cronograma_*' en
   'CRONOGRAMA', que los lee CronogramaPagos::configDesdeMapa() por clave.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   Todo sigue andando como hoy. El calendario bancario es RO_T_CALENDARIO sin
   ajustes (aplicar() devuelve el mapa intacto si la tabla no existe), y la
   sub-pestana Calendario avisa que falta este script: la grilla de feriados
   bancarios se ve pero no deja agregar ni quitar. El horizonte y los feriados
   de comercio se siguen viendo y editando en Generales. El cronograma de pagos
   se ve en Calendario igual, porque sus tablas son otras.

   ES REEJECUTABLE: la tabla pregunta por OBJECT_ID y la migracion solo toca
   las filas que todavia no estan en CALENDARIO. La segunda corrida no modifica
   ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NULL
BEGIN
    RAISERROR('Corre primero sql/ventas_proyeccion.sql: no existe RO_T_CASHFLOW_PARAMETROS.', 16, 1);
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_CALENDARIO_AJUSTE
   Los dias que el cashflow considera distinto que RO_T_CALENDARIO.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CALENDARIO_AJUSTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_CALENDARIO_AJUSTE (
        ID              INT IDENTITY(1,1) NOT NULL,
        FECHA           DATE          NOT NULL,
        /* El valor que VALE para esa fecha, con la misma convencion que
           RO_T_CALENDARIO.DIA_LABORAL: 0 feriado, 1 habil. */
        DIA_LABORAL     BIT           NOT NULL,
        /* Obligatoria al marcar un feriado (la exige el PHP): es lo unico que
           dice que feriado es, porque RO_T_CALENDARIO no tiene el nombre. Al
           pasar a habil un feriado bancario es opcional. */
        DESCRIPCION     VARCHAR(200)  NULL,
        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_CALAJ_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_CALAJ_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_CALAJ_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_CALENDARIO_AJUSTE PRIMARY KEY CLUSTERED (ID),
        /* Lunes a viernes en la base tambien, y no solo en el PHP: el endpoint
           es alcanzable sin pasar por la pantalla. Se calcula con DATEDIFF
           contra un lunes conocido (1900-01-01) para no depender de
           SET DATEFIRST, que cambia lo que devuelve DATEPART(weekday). */
        CONSTRAINT CK_RO_T_CF_CALAJ_LUN_VIE
            CHECK (DATEDIFF(DAY, '19000101', FECHA) % 7 < 5)
    );

    /* UN SOLO AJUSTE VIGENTE POR FECHA. Filtrado por VIGENTE = 1: un UNIQUE
       comun prohibiria tambien las filas historicas de la misma fecha, que es
       justamente lo que esta tabla existe para guardar. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_CALAJ_VIGENTE
        ON dbo.RO_T_CASHFLOW_CALENDARIO_AJUSTE (FECHA)
        WHERE VIGENTE = 1;

    PRINT 'RO_T_CASHFLOW_CALENDARIO_AJUSTE creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_CALENDARIO_AJUSTE ya existia.';
GO

/* ============================================================================
   2. LA MIGRACION DE LOS PARAMETROS DE FECHAS

   Por CLAVE y no por (MODULO, GRUPO): de GENERALES/GENERAL se van tres de
   seis -la alicuota y los dos de inflacion se quedan-, asi que no hay un
   criterio de grupo que los separe. Los 'cronograma_*' van por prefijo: un
   concepto nuevo del cronograma trae sus dos claves y tiene que viajar con
   las demas.

   Se mueven desde CUALQUIER modulo, no solo desde GENERALES: si este script
   corre en una base donde todavia no se corrio el de Generales, los tres
   primeros siguen en VENTAS, y dejarlos ahi seria una sub-pestana Ventas
   mostrando parametros que ya no son suyos.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    PRINT 'AVISO: RO_T_CASHFLOW_PARAMETROS no tiene columna MODULO, asi que no hay nada que '
        + 'migrar. Corre sql/migracion_parametros_modulo.sql y volve a correr este script.';
END
ELSE
BEGIN
    DECLARE @migrados INT;

    UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
    SET MODULO = 'CALENDARIO'
    WHERE (CLAVE IN ('horizonte_dias', 'horizonte_meses', 'feriados_comercio')
           OR CLAVE LIKE 'cronograma[_]%')
      AND ISNULL(MODULO, '') <> 'CALENDARIO';

    SET @migrados = @@ROWCOUNT;

    PRINT 'Parametros movidos a CALENDARIO: ' + CAST(@migrados AS VARCHAR(10))
        + ' (ningun VALOR se toco).';
END
GO

/* ============================================================================
   3. CONTROL: COMO QUEDO
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NOT NULL
BEGIN
    SELECT CLAVE, MODULO, GRUPO, VALOR
    FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE MODULO IN ('GENERALES', 'CALENDARIO')
    ORDER BY MODULO, GRUPO, CLAVE;

    /* Lo que tiene que quedar vacio: ningun parametro de fechas fuera de
       CALENDARIO. */
    DECLARE @sueltos INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_PARAMETROS
                            WHERE (CLAVE IN ('horizonte_dias', 'horizonte_meses', 'feriados_comercio')
                                   OR CLAVE LIKE 'cronograma[_]%')
                              AND ISNULL(MODULO, '') <> 'CALENDARIO');

    IF @sueltos > 0
        PRINT 'AVISO: quedan ' + CAST(@sueltos AS VARCHAR(10)) + ' parametro(s) de fechas fuera '
            + 'de CALENDARIO.';
    ELSE
        PRINT 'OK: los parametros de fechas estan todos en CALENDARIO.';
END

SELECT COUNT(*) AS AJUSTES_VIGENTES
FROM dbo.RO_T_CASHFLOW_CALENDARIO_AJUSTE
WHERE VIGENTE = 1;
GO
