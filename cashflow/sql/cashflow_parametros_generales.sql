/* ============================================================================
   MODULO CASHFLOW - SUB-PESTANA "GENERALES" DE PARAMETROS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/ventas_proyeccion.sql (o de sql/cashflow_estructura.sql)
            y DESPUES de sql/migracion_parametros_modulo.sql. El primero crea
            RO_T_CASHFLOW_PARAMETROS; el segundo le agrega la columna MODULO,
            que es la que este script reescribe. Si MODULO no existe, este
            script NO migra nada y lo dice: sin esa columna la pestana muestra
            todos los parametros como de Ventas, que es lo que ya hacia.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   1. MIGRA al modulo 'GENERALES' los parametros que hoy estan como
      MODULO = 'VENTAS' y GRUPO = 'GENERAL'. NO TOCA NINGUN VALOR: solo cambia
      donde se dibujan.
   2. RO_T_CASHFLOW_INFLACION_MES: el % de inflacion esperado de cada mes
      calendario.
   3. RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT: los overrides de las fechas del
      cronograma de pagos (2do y 4to viernes), con su historial.
   4. Los dos parametros del modulo: la modalidad de carga de la inflacion y el
      porcentaje unico de la modalidad constante.

   ----------------------------------------------------------------------------
   POR QUE LOS GENERALES SE VAN DE VENTAS

   'alicuota_iva', 'horizonte_dias', 'horizonte_meses' y 'feriados_comercio' no
   son de Ventas: el horizonte es EL EJE DE TODO EL MODULO -lo lee
   Horizonte::desdeParametros() y con el se dibujan el tablero y las diez
   pestanas con eje temporal- y los feriados de comercio marcan columnas en
   todas ellas. Que vivieran bajo la sub-pestana Ventas hacia creer que tocarlos
   solo movia esa pantalla.

   NINGUN CALCULO SE ENTERA DE ESTE CAMBIO, y eso esta verificado: el unico
   lugar del codigo que pide parametros POR MODULO es
   Parametros::getModulosConDatos(), que arma la pestana. Todas las formulas
   los leen por CLAVE con getParametrosMap(), que no filtra por modulo. Despues
   de correr esto, Horizonte, Ventas, el tablero y las demas pestanas leen
   exactamente los mismos valores.

   ----------------------------------------------------------------------------
   LA INFLACION: POR MES CALENDARIO, Y LOS MESES VIEJOS NO SE BORRAN

   Un ajuste trimestral del valor hora de Logistica Local usa la inflacion de SU
   mes y la de los DOS ANTERIORES (dic-26 = oct + nov + dic). O sea que un mes
   sigue haciendo falta durante tres meses despues de haber pasado. Por eso la
   tabla guarda un mes calendario por fila y NADA la depura: la ventana que la
   pantalla deja editar se mueve sola con el calendario, pero lo cargado queda.

   LA MODALIDAD ES UN PARAMETRO Y LA TABLA ES LA QUE MANDA. En modalidad
   CONSTANTE la pantalla pide UN porcentaje y lo estampa en todos los meses de
   la ventana; en VARIABLE se edita mes por mes. En las dos, lo que el calculo
   lee es SIEMPRE esta tabla. El parametro 'inflacion_pct_constante' es lo que
   alguien tipeo la ultima vez, no lo que se aplica: si despues se pasa a
   VARIABLE y se corrige un mes, ese mes vale lo que dice la tabla y el
   parametro queda como estaba.

   ----------------------------------------------------------------------------
   EL CRONOGRAMA: SOLO LOS OVERRIDES

   Las fechas NO se guardan. El 2do y el 4to viernes de un mes se calculan, y el
   corrimiento al dia habil anterior tambien: materializarlos seria tener una
   copia que se desactualiza sola el dia que cambia un feriado en
   RO_T_CALENDARIO. Lo unico que esta tabla guarda es lo que una persona decidio
   distinto, que es lo unico que no se puede recalcular.

   SIN BAJAS FISICAS, igual que RO_T_CASHFLOW_COMEX_FECHA_EDIT: volver al valor
   calculado marca VIGENTE = 0 y deja la fila. Con un DELETE, "esta fecha nunca
   se toco" y "se toco y se volvio atras" son indistinguibles despues del hecho.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La pestana Parametros NO falla. La sub-pestana Generales aparece igual y
   avisa que falta el script: los cuatro parametros que migra se siguen viendo
   -bajo Ventas, como hoy- y las tarjetas de inflacion y cronograma se dibujan
   apagadas nombrando este archivo. Logistica Local proyecta el valor base de
   cada fletero sin ningun ajuste trimestral y lo avisa, y reparte los pagos en
   el 2do y 4to viernes calculados, sin overrides.

   ES REEJECUTABLE: cada tabla pregunta por OBJECT_ID, cada parametro por su
   CLAVE y la migracion solo toca las filas que todavia no estan en GENERALES.
   La segunda corrida no modifica ninguna fila.
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
   1. LA MIGRACION DE LOS GENERALES

   Se mueven por (MODULO, GRUPO) y no por una lista de claves escrita a mano:
   la sub-pestana Generales muestra "todo lo que hoy es VENTAS/GENERAL", asi que
   una clave que alguien haya agregado ahi tiene que viajar con las demas. Si se
   listaran las cuatro conocidas, esa quinta quedaria sola en una pestana Ventas
   que ya no tiene bloque de generales: existiria y no se veria en ningun lado.

   'dias_prechequeado' VIAJA TAMBIEN, aunque este retirado. Esta en
   Parametros::RETIRADOS y la pantalla no lo dibuja, pero la fila existe y tiene
   el valor con el que se proyecto en su momento. Dejarlo en VENTAS/GENERAL lo
   convertiria en la unica fila de un grupo que ya no se resuelve.

   EL GRUPO NO CAMBIA: sigue siendo 'GENERAL'. La pestana resuelve la seccion
   'generales' pidiendo el GRUPO 'GENERAL' del modulo, asi que cambiarlo dejaria
   los campos invisibles. Lo que cambia es el MODULO y nada mas.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    PRINT 'AVISO: RO_T_CASHFLOW_PARAMETROS no tiene columna MODULO, asi que no hay nada que '
        + 'migrar. Corre sql/migracion_parametros_modulo.sql y volve a correr este script. '
        + 'Mientras tanto la pestana muestra todos los parametros como de Ventas.';
END
ELSE
BEGIN
    DECLARE @migrados INT;

    UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
    SET MODULO = 'GENERALES'
    WHERE MODULO = 'VENTAS' AND GRUPO = 'GENERAL';

    SET @migrados = @@ROWCOUNT;

    PRINT 'Parametros movidos de VENTAS a GENERALES: ' + CAST(@migrados AS VARCHAR(10))
        + ' (ningun VALOR se toco).';
END
GO

/* ============================================================================
   2. RO_T_CASHFLOW_INFLACION_MES
   El % de inflacion esperado de cada mes calendario.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_INFLACION_MES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_INFLACION_MES (
        /* 'YYYY-MM'. CHAR(7) y no DATE, por el mismo motivo que
           RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE.MES: es un mes, no un dia, y
           guardarlo como fecha obliga a elegir un dia que no significa nada y
           que despues hay que acordarse de ignorar. */
        MES          CHAR(7)       NOT NULL,
        /* En PUNTOS PORCENTUALES: 2.0000 es 2 %, no 0,02. Es como se tipea y
           como se lee en pantalla, y la unica conversion vive en el calculo.
           Cuatro decimales porque un ajuste trimestral SUMA tres meses SIN
           componer, asi que el redondeo del mes se arrastra entero. */
        PORCENTAJE   DECIMAL(9,4)  NOT NULL,
        /* Con cual de las dos modalidades se cargo este mes. No cambia el
           calculo -lo que se aplica es PORCENTAJE- pero es lo que explica por
           que once meses tienen el mismo numero: si fue una carga constante o
           si alguien puso el mismo valor once veces. */
        MODALIDAD    VARCHAR(10)   NOT NULL
            CONSTRAINT DF_RO_T_CF_INFLA_MODALIDAD DEFAULT ('VARIABLE'),
        USUARIO      VARCHAR(50)   NULL,
        FECHA_UPDATE DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_INFLA_UPDATE DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_INFLACION_MES PRIMARY KEY CLUSTERED (MES),
        /* La lista cerrada va en la base y no solo en el PHP: el endpoint es
           alcanzable sin pasar por la pantalla. Mismo criterio que el CHECK de
           CAMPO en RO_T_CASHFLOW_COMEX_FECHA_EDIT. */
        CONSTRAINT CK_RO_T_CF_INFLA_MODALIDAD
            CHECK (MODALIDAD IN ('CONSTANTE', 'VARIABLE'))
    );

    PRINT 'RO_T_CASHFLOW_INFLACION_MES creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_INFLACION_MES ya existia.';
GO

/* ============================================================================
   3. RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT
   Las fechas del cronograma que una persona movio.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT (
        ID               INT IDENTITY(1,1) NOT NULL,
        /* Mes del cronograma, 'YYYY-MM'. */
        MES              CHAR(7)      NOT NULL,
        /* 1 = el pago del 2do viernes, 2 = el del 4to. SIEMPRE SON DOS: el
           numero de pago identifica la fila del cronograma y no depende de la
           fecha, asi que un override sobrevive a que cambie un feriado. */
        NRO_PAGO         TINYINT      NOT NULL,
        FECHA            DATE         NOT NULL,
        /* Lo que daba el calculo cuando se cargo el override. Es contra que se
           compara la fecha puesta a mano, y sin eso la pantalla no puede decir
           de cuanto fue la correccion. Mismo criterio que FECHA_ANTERIOR en
           RO_T_CASHFLOW_COMEX_FECHA_EDIT. */
        FECHA_CALCULADA  DATE         NULL,
        MOTIVO           VARCHAR(300) NULL,
        VIGENTE          BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_CRONOED_VIGENTE DEFAULT (1),
        USUARIO          VARCHAR(50)  NULL,
        FECHA_ALTA       DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_CRONOED_ALTA DEFAULT (GETDATE()),
        /* Cuando dejo de ser el override vigente. Con FECHA_ALTA sola no se
           distingue una fecha corregida a los cinco minutos de una que estuvo
           vigente tres semanas. */
        FECHA_BAJA       DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_CRONOED_NRO CHECK (NRO_PAGO IN (1, 2))
    );

    /* UN SOLO OVERRIDE VIGENTE POR (MES, NRO_PAGO). Filtrado por VIGENTE = 1:
       un UNIQUE comun prohibiria tambien las filas historicas del mismo pago,
       que es justamente lo que esta tabla existe para guardar. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_CRONOED_VIGENTE
        ON dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT (MES, NRO_PAGO)
        WHERE VIGENTE = 1;

    PRINT 'RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT ya existia.';
GO

/* ============================================================================
   4. LOS DOS PARAMETROS DEL MODULO

   Se insertan solo los que faltan: si alguien ya ajusto un valor desde
   Parametros, este script no lo pisa.

   VAN EN GRUPO 'GENERAL', como el resto: la pestana resuelve la seccion
   'generales' pidiendo ese grupo, y un grupo propio necesitaria ademas una
   seccion nueva en Parametros::getModulosConDatos() y su bloque en el front.
   ============================================================================ */
DECLARE @p TABLE (CLAVE VARCHAR(50), VALOR VARCHAR(200), TIPO_DATO VARCHAR(20),
                  DESCRIPCION VARCHAR(200), GRUPO VARCHAR(50));

INSERT INTO @p (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO) VALUES
 ('inflacion_modalidad', 'CONSTANTE', 'TEXT',
  'Como se carga la inflacion mensual: CONSTANTE (un unico % para todos los meses) o VARIABLE (un % por mes)',
  'GENERAL'),
 ('inflacion_pct_constante', '2', 'DECIMAL',
  'El % mensual de la modalidad constante. Es lo ultimo que se tipeo; lo que se aplica es siempre RO_T_CASHFLOW_INFLACION_MES',
  'GENERAL');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, FECHA_UPDATE)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO, GETDATE()
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);
END
ELSE
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO, FECHA_UPDATE)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO, 'GENERALES', GETDATE()
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    /* Un parametro que ya existia apuntado a otro modulo se reapunta: NO se le
       toca el VALOR, solo donde se muestra. */
    UPDATE x
    SET MODULO = 'GENERALES'
    FROM dbo.RO_T_CASHFLOW_PARAMETROS x
    JOIN @p p ON p.CLAVE = x.CLAVE
    WHERE ISNULL(x.MODULO, '') <> 'GENERALES';
END

/* El grupo y el tipo tambien se corrigen, y tampoco tocan el VALOR: el primero
   decide si el campo se dibuja, el segundo que control usa el formulario. */
UPDATE x
SET GRUPO = p.GRUPO, TIPO_DATO = p.TIPO_DATO
FROM dbo.RO_T_CASHFLOW_PARAMETROS x
JOIN @p p ON p.CLAVE = x.CLAVE
WHERE ISNULL(x.GRUPO, '') <> p.GRUPO OR ISNULL(x.TIPO_DATO, '') <> p.TIPO_DATO;
GO

/* ============================================================================
   5. CONTROL: COMO QUEDO
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NOT NULL
BEGIN
    SELECT MODULO, GRUPO, COUNT(*) AS PARAMETROS
    FROM dbo.RO_T_CASHFLOW_PARAMETROS
    GROUP BY MODULO, GRUPO
    ORDER BY MODULO, GRUPO;

    /* Lo que tiene que quedar vacio: ningun parametro suelto en VENTAS/GENERAL.
       Si aparece alguno, es que se cargo DESPUES de correr este script con el
       modulo equivocado. */
    DECLARE @sueltos INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_PARAMETROS
                            WHERE MODULO = 'VENTAS' AND GRUPO = 'GENERAL');

    IF @sueltos > 0
        PRINT 'AVISO: quedan ' + CAST(@sueltos AS VARCHAR(10)) + ' parametro(s) en '
            + 'VENTAS/GENERAL. La sub-pestana Ventas ya no dibuja generales, asi que '
            + 'no se ven en ningun lado: hay que pasarlos a GENERALES.';
    ELSE
        PRINT 'OK: no quedan parametros en VENTAS/GENERAL.';
END
GO
