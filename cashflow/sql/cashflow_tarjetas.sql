/* ============================================================================
   MODULO CASHFLOW - TARJETAS Y SUS RESUMENES
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_parametros_generales.sql, que crea
            RO_T_CASHFLOW_INFLACION_MES y RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT.
            Sin el, las dos tablas se crean igual y la pestana funciona: las
            estimaciones que necesitan inflacion quedan en NULL avisando qué mes
            falta, y la parte en efectivo de Gastos Supervisoras se reparte en
            las fechas calculadas del cronograma, sin overrides.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   Dos tablas, que son un solo circuito:

     RO_T_CASHFLOW_TARJETAS          el maestro: una fila por tarjeta
     RO_T_CASHFLOW_TARJETAS_RESUMEN  el resumen de cada tarjeta y mes

   Van juntas en un script porque la segunda tiene una FK contra la primera, asi
   que el orden entre las dos no puede quedar a criterio de quien las corre.

   NO CREA NINGUNA FILA DEL TABLERO. Eso lo hace sql/cashflow_tarjetas_fila.sql,
   que va al final de la serie.

   ----------------------------------------------------------------------------
   NO SE CREA LA VISTA DE USUARIOS: YA EXISTE

   RO_V_CASHFLOW_USUARIOS_TARJETAS une los directores activos
   ([XL-APPS].sistemas.dbo.RO_T_DIRECTORES) con las supervisoras activas
   ([XL-LAKERBIS].LOCALES_LAKERS.dbo.RO_T_SUPERVISORAS_COMERCIAL) y agrega una
   fila fija. Este script SOLO VERIFICA que este, y avisa si falta; la pantalla
   hace lo mismo con OBJECT_ID y se dibuja apagada en vez de fallar.

   SUS COLUMNAS SON ID_DIRECTOR Y NOMBRE, no ID: el nombre de la primera columna
   lo pone el primer SELECT del UNION, que es el de directores. Vale para los
   dos tipos de usuario.

   VERIFICADO EL 26/09/2026: 14 filas, y GROUP BY ID_DIRECTOR HAVING COUNT(*) > 1
   no devuelve ninguna. Por eso ID_USUARIO alcanza como referencia al usuario y
   no hace falta una clave compuesta. Los dos espacios de IDs son disjuntos POR
   ACCIDENTE -supervisoras 1..11, directores 1115 y siguientes-, no por
   construccion: el control de abajo esta para que el dia que se toquen se vea.

   ----------------------------------------------------------------------------
   EL TIPO DECIDE EN QUE SUB-PESTANA APARECE, Y NO ES LO MISMO QUE EL USUARIO

   TIPO es CORPORATIVA, SUPERVISORA o SOCIO, y es lo unico que decide en cual de
   las tres sub-pestanas se ve la tarjeta y con que regla se estima. Es
   INDEPENDIENTE de quien sea el usuario: la gerenta de administracion y finanzas
   figura en la vista al lado de las supervisoras y su tarjeta es CORPORATIVA.

   Solo las de tipo SUPERVISORA se asocian a una supervisora POR NOMBRE contra
   RO_T_SUPERVISORAS_COMERCIAL. Para los otros dos tipos el nombre del usuario es
   descriptivo y no cruza contra nada.

   ----------------------------------------------------------------------------
   UNA TARJETA NO TIENE MONEDA

   La misma tarjeta puede tener gastos en pesos y en dolares, asi que la moneda
   vive en el RESUMEN -IMPORTE_ARS e IMPORTE_USD, con al menos uno cargado- y no
   en el maestro. Un campo de moneda en la tarjeta obligaria a dar de alta dos
   tarjetas para la misma tarjeta fisica, y entonces el numero de resumen no
   coincidiria con ninguna de las dos.

   ----------------------------------------------------------------------------
   LA IDENTIDAD DE UNA TARJETA: BANCO + USUARIO + ULTIMOS 4

   ULTIMOS_4 es OPTATIVO, pero es lo unico que distingue dos tarjetas del mismo
   usuario en el mismo banco. Por eso el indice unico es (COD_BANCO, ID_USUARIO,
   ULTIMOS_4) y NO esta filtrado por ACTIVA:

     - SQL Server trata los NULL como iguales en un UNIQUE, asi que la segunda
       tarjeta del mismo usuario en el mismo banco SIN los ultimos 4 la rechaza
       la base. Es exactamente la regla: ahi ULTIMOS_4 pasa a ser obligatorio.
       El backend lo dice antes y mejor, pero la red esta.

     - Sin filtrar por ACTIVA, dar de baja una tarjeta y volver a cargarla
       REACTIVA la misma fila en vez de crear una segunda. Asi los resumenes
       viejos siguen colgando de la tarjeta que los tuvo, que es lo que la baja
       logica promete conservar.

   EL TIPO NO ENTRA EN LA IDENTIDAD, y es a proposito: dos filas con el mismo
   banco, usuario y ultimos 4 pero distinto tipo serian la MISMA tarjeta fisica
   cargada dos veces, y sus resumenes se contarian dos veces en el tablero.

   ----------------------------------------------------------------------------
   DIA_VENCIMIENTO ES OBLIGATORIO, A DIFERENCIA DE LAS HORAS DE UN FLETERO

   En Logistica los tres numeros nacen en NULL porque son lo que se negocia y
   puede no estar acordado todavia. Acá es al revés: el dia de vencimiento del
   resumen lo fija el banco y se sabe en el momento en que la tarjeta existe.

   Y sin el NO HAY NINGUNA FECHA en la que poner la estimacion: una tarjeta con
   el dia en NULL no proyectaria un solo peso en ningun mes, asi que dejarlo
   opcional solo habilita crear una tarjeta que no puede servir para nada. Se
   exige en el alta, y no hay ningun dia por defecto: un DEFAULT convertiria la
   falta de dato en un dato.

   ----------------------------------------------------------------------------
   PCT_COBERTURA VA EN PUNTOS, NO EN TASA

   5 es 5 %, igual que RO_T_CASHFLOW_INFLACION_MES y al revés que la alicuota de
   IVA, que se guarda como 0,21. La unica division por 100 vive en quien aplica
   el porcentaje.

   SE APLICA EN LOS TRES TIPOS, sobre la BASE ESTIMADA de cada uno: la parte
   tarjeta en Gastos Supervisoras, y los dos componentes en Tarjetas Socios. En
   Tarjetas Pagos Corporativos NO multiplica nada: ahi la base son facturas
   reales de Tango y alterarlas seria inventar deuda, asi que la cobertura entra
   como un renglon de estimacion aparte. El DEFAULT 0 es el unico que la tabla
   declara, y significa lo que dice: sin cobertura.

   ----------------------------------------------------------------------------
   EL RESUMEN PISA LA ESTIMACION, Y POR ESO LA CLAVE ES (TARJETA, MES)

   Un resumen por tarjeta y mes de vencimiento, con indice unico FILTRADO por
   ACTIVO = 1: el historial vive en las filas con ACTIVO = 0 y un UNIQUE comun
   prohibiria justamente las repetidas del historial. Mismo criterio que
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO y RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO.

   MES es el mes del VENCIMIENTO del resumen, no el de los consumos: es con eso
   que pisa la estimacion de ese mes, que esta puesta en el DIA_VENCIMIENTO de
   la tarjeta.

   LA FECHA DEL RESUMEN NO SE CORRE AL DIA HABIL. Se tipea y vale: es un hecho
   que alguien leyó del resumen, no una fecha calculada. La que se corre es la
   de la ESTIMACION, que la calcula el codigo.

   ORIGEN distingue CARGA -el resumen del periodo, que pisa la estimacion- de
   HISTORICO -la carga inicial de base de Tarjetas Socios-. Los dos cuentan como
   historia para la base de la estimacion; lo que cambia es de donde salieron, y
   eso es lo que despues explica por que hay tres resumenes viejos cargados el
   mismo dia.

   ----------------------------------------------------------------------------
   UN RESUMEN NO PUEDE SER NEGATIVO NI CERO

   Los dos importes son NULL-ables -al menos uno tiene que estar- pero cuando
   estan tienen que ser > 0. Un cero no se distingue de un olvido, y un negativo
   -un saldo a favor- convertiria un EGRESO del tablero en un ingreso que nadie
   afirmo, en una fila cuyo TIPO es EGRESO. Si algun dia hay que modelar un saldo
   a favor, es una decision y no una carga.

   ----------------------------------------------------------------------------
   AUDITORIA EN LAS DOS TABLAS

   USUARIO_ALTA / FECHA_ALTA / USUARIO_MODIF / FECHA_MODIF, y en la de resumenes
   ademas USUARIO_BAJA / FECHA_BAJA, porque tiene baja logica. Los escribe
   SIEMPRE el backend con el usuario de la sesion; el front no manda ninguno de
   los cuatro.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La pestana Financiero -> Pagos con Tarjetas y Otros NO falla: las tres
   sub-pestanas avisan que falta este script y se dibujan vacias, y la
   sub-pestana Parametros -> Tarjetas se dibuja apagada nombrandolo. La fila del
   tablero va en CERO y el proveedor lo dice con su propio aviso, aclarando que
   un cero no significa que no haya que pagar nada.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes, y no se
   siembra ninguna tarjeta. La segunda corrida no modifica ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas. Ninguna impide crear las tablas: avisan.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS') IS NULL
BEGIN
    PRINT 'AVISO: no existe dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS. Las tablas se crean igual, '
        + 'pero el alta de tarjetas no va a tener de donde elegir el usuario y la pantalla '
        + 'lo avisa. ESTA VISTA NO LA CREA ESTE SCRIPT: se mantiene aparte.';
END
ELSE
BEGIN
    PRINT 'RO_V_CASHFLOW_USUARIOS_TARJETAS presente.';
END
GO

IF OBJECT_ID('dbo.BANCO') IS NULL
BEGIN
    PRINT 'AVISO: no existe dbo.BANCO. Las tablas se crean igual, pero el alta no va a poder '
        + 'validar el codigo de banco ni mostrar su descripcion: la pantalla lo avisa.';
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_TARJETAS
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* DECIDE EN QUE SUB-PESTANA APARECE y con que regla se estima. Ver la
           nota del encabezado: no es lo mismo que quien es el usuario. */
        TIPO            VARCHAR(12)   NOT NULL,

        /* COLLATE Modern_Spanish_CI_AI: la MISMA que BANCO.COD_BANCO, que es un
           D_CODIGO. Declararla explicitamente evita que un cambio de collation
           de la base deje esta columna y la de Tango comparandose distinto.
           VARCHAR(10) tambien es el largo de alla. */
        COD_BANCO       VARCHAR(10)   COLLATE Modern_Spanish_CI_AI NOT NULL,

        /* El usuario sale de RO_V_CASHFLOW_USUARIOS_TARJETAS. Ver la nota: hoy
           el ID no se repite entre directores y supervisoras, asi que alcanza. */
        ID_USUARIO      INT           NOT NULL,

        /* EL NOMBRE ES UNA COPIA, NO LA FUENTE. Se guarda para que la tarjeta
           siga siendo legible el dia que ese usuario deje de estar activo en la
           vista -y entonces desaparezca de ella- porque un ID suelto no le dice
           nada a nadie. La pantalla muestra el de la vista cuando lo encuentra,
           asi que un cambio de nombre se ve enseguida.

           NVARCHAR y Modern_Spanish_CI_AI: la misma forma que la columna NOMBRE
           de la vista. Con VARCHAR se perderia cualquier caracter que no entre
           en la code page de la base. */
        NOMBRE_USUARIO  NVARCHAR(200) COLLATE Modern_Spanish_CI_AI NOT NULL,

        /* OPTATIVO en general, OBLIGATORIO cuando el mismo usuario tiene mas de
           una tarjeta en el mismo banco. Lo exige el backend con un mensaje que
           se entiende, y el indice unico de abajo es la red. CHAR(4) con CHECK de
           cuatro digitos: '0012' no es lo mismo que 12, asi que no es un numero. */
        ULTIMOS_4       CHAR(4)       NULL,

        /* En PUNTOS: 5 es 5 %. Ver la nota del encabezado sobre por que en
           Corporativas no multiplica nada. */
        PCT_COBERTURA   DECIMAL(9,4)  NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJ_PCT DEFAULT (0),

        /* Dia del mes en que vence el resumen, 1..31. Es la fecha de la
           ESTIMACION de los meses sin resumen cargado. Un mes que no tiene ese
           dia usa su ultimo dia, y si cae en un dia no habil se corre al primer
           habil SIGUIENTE; las dos reglas viven en Class/TarjetasVencimiento.php
           y no en la base, porque dependen del calendario.

           OBLIGATORIO y sin DEFAULT. Ver la nota del encabezado. */
        DIA_VENCIMIENTO TINYINT       NOT NULL,

        /* Baja logica: una tarjeta inactiva deja de proyectar y conserva sus
           resumenes. */
        ACTIVA          BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJ_ACTIVA DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJ_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJ_MODIF DEFAULT (GETDATE()),

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS PRIMARY KEY CLUSTERED (ID),

        /* El CHECK va en la base y no solo en el PHP: el endpoint es alcanzable
           sin pasar por la pantalla. Mismo criterio que el CHECK de MODALIDAD de
           RO_T_CASHFLOW_INFLACION_MES. */
        CONSTRAINT CK_RO_T_CF_TARJ_TIPO
            CHECK (TIPO IN ('CORPORATIVA', 'SUPERVISORA', 'SOCIO')),

        CONSTRAINT CK_RO_T_CF_TARJ_ULTIMOS4
            CHECK (ULTIMOS_4 IS NULL OR ULTIMOS_4 LIKE '[0-9][0-9][0-9][0-9]'),

        /* 0 es valido y significa "sin cobertura"; 100 es el tope defensivo. Un
           porcentaje de 5000 tipeado de mas es un numero perfectamente valido
           que multiplica el egreso por cincuenta sin que nada avise. Mismo
           criterio que Inflacion::PCT_MAX. */
        CONSTRAINT CK_RO_T_CF_TARJ_PCT
            CHECK (PCT_COBERTURA >= 0 AND PCT_COBERTURA <= 100),

        CONSTRAINT CK_RO_T_CF_TARJ_DIA
            CHECK (DIA_VENCIMIENTO BETWEEN 1 AND 31),

        CONSTRAINT CK_RO_T_CF_TARJ_BANCO
            CHECK (LEN(LTRIM(RTRIM(COD_BANCO))) > 0),

        CONSTRAINT CK_RO_T_CF_TARJ_NOMBRE
            CHECK (LEN(LTRIM(RTRIM(NOMBRE_USUARIO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS. No se sembro ninguna tarjeta: se dan de alta '
        + 'desde Parametros -> Tarjetas.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   1b. LA IDENTIDAD DE UNA TARJETA. Ver la nota del encabezado sobre por que NO
       va filtrado por ACTIVA y por que el TIPO no participa.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_TARJ_IDENTIDAD'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJ_IDENTIDAD
        ON dbo.RO_T_CASHFLOW_TARJETAS (COD_BANCO, ID_USUARIO, ULTIMOS_4);

    PRINT 'Creado UX_RO_T_CF_TARJ_IDENTIDAD (banco + usuario + ultimos 4).';
END
GO

/* ----------------------------------------------------------------------------
   1c. El indice de la consulta de cada carga: "las activas de este tipo".
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_RO_T_CF_TARJ_TIPO_ACTIVA'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_TARJ_TIPO_ACTIVA
        ON dbo.RO_T_CASHFLOW_TARJETAS (TIPO, ACTIVA)
        INCLUDE (COD_BANCO, ID_USUARIO, NOMBRE_USUARIO, ULTIMOS_4,
                 PCT_COBERTURA, DIA_VENCIMIENTO);
END
GO

/* ============================================================================
   2. RO_T_CASHFLOW_TARJETAS_RESUMEN
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN (
        ID                INT IDENTITY(1,1) NOT NULL,
        ID_TARJETA        INT           NOT NULL,

        /* El mes del VENCIMIENTO del resumen, 'YYYY-MM'. CHAR(7) y no DATE por
           el mismo motivo que los meses de inflacion y de valor hora: es un mes,
           no un dia, y una fecha invitaria a preguntarle el dia. */
        MES               CHAR(7)       NOT NULL,

        /* AL MENOS UNO. Pueden venir los dos: la misma tarjeta tiene consumos en
           pesos y en dolares. Ver el CHECK de abajo. */
        IMPORTE_ARS       DECIMAL(18,2) NULL,
        IMPORTE_USD       DECIMAL(18,2) NULL,

        /* La fecha que dice el resumen. NO se corre al dia habil: es un hecho
           tipeado, no una fecha calculada. */
        FECHA_VENCIMIENTO DATE          NOT NULL,

        /* PAGADO SACA EL RESUMEN DEL HORIZONTE: esa plata ya salio y ya esta en
           el saldo bancario que abre el cuadro. Se puede desmarcar, y entonces
           vuelve a proyectarse. */
        PAGADO            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRES_PAGADO DEFAULT (0),
        FECHA_PAGADO      DATETIME      NULL,
        USUARIO_PAGADO    VARCHAR(50)   NULL,

        /* CARGA = el resumen del periodo, que pisa la estimacion.
           HISTORICO = la carga inicial de base de Tarjetas Socios.
           Los dos son historia; lo que cambia es de donde salieron. */
        ORIGEN            VARCHAR(10)   NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRES_ORIGEN DEFAULT ('CARGA'),

        OBSERVACION       VARCHAR(500)  NULL,

        /* Baja logica. El historial vive en las filas con ACTIVO = 0. */
        ACTIVO            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRES_ACTIVO DEFAULT (1),

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRES_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJRES_MODIF DEFAULT (GETDATE()),

        /* QUIEN Y CUANDO LO DIO DE BAJA. Con FECHA_MODIF sola no se distingue
           una baja de una correccion del importe. */
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_RESUMEN PRIMARY KEY CLUSTERED (ID),

        /* La FK es lo que garantiza que no quede un resumen colgado de una
           tarjeta que no existe. NO lleva ON DELETE: las tarjetas no se borran,
           se dan de baja, y un cascade escondería el dia que alguien intente
           borrar una. */
        CONSTRAINT FK_RO_T_CF_TARJRES_TARJETA FOREIGN KEY (ID_TARJETA)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS (ID),

        CONSTRAINT CK_RO_T_CF_TARJRES_MES
            CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),

        CONSTRAINT CK_RO_T_CF_TARJRES_ORIGEN
            CHECK (ORIGEN IN ('CARGA', 'HISTORICO')),

        /* AL MENOS UNO DE LOS DOS IMPORTES. Un resumen sin ningun importe no es
           un resumen: es una fila que pisa la estimacion con nada. */
        CONSTRAINT CK_RO_T_CF_TARJRES_ALGUN_IMPORTE
            CHECK (IMPORTE_ARS IS NOT NULL OR IMPORTE_USD IS NOT NULL),

        /* Ni cero ni negativo. Ver la nota del encabezado. */
        CONSTRAINT CK_RO_T_CF_TARJRES_ARS
            CHECK (IMPORTE_ARS IS NULL OR IMPORTE_ARS > 0),
        CONSTRAINT CK_RO_T_CF_TARJRES_USD
            CHECK (IMPORTE_USD IS NULL OR IMPORTE_USD > 0),

        /* Un resumen pagado tiene que decir CUANDO. Al revés tambien: una fecha
           de pago sobre un resumen sin pagar seria un rastro de algo que se
           desmarco, y para eso esta el historial. */
        CONSTRAINT CK_RO_T_CF_TARJRES_PAGADO
            CHECK ((PAGADO = 0 AND FECHA_PAGADO IS NULL)
                OR (PAGADO = 1 AND FECHA_PAGADO IS NOT NULL))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2b. UN SOLO RESUMEN VIGENTE POR TARJETA Y MES.

   Filtrado por ACTIVO = 1: el historial son las filas con ACTIVO = 0, y un
   UNIQUE comun prohibiria justo las repetidas que el historial necesita tener.
   Es ademas el indice de la consulta de cada carga.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_TARJRES_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJRES_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN (ID_TARJETA, MES)
        WHERE ACTIVO = 1;

    PRINT 'Creado UX_RO_T_CF_TARJRES_VIGENTE (tarjeta + mes, solo los activos).';
END
GO

/* ============================================================================
   3. CONTROLES: COMO QUEDO

   Ninguno inserta ni modifica nada. Se imprimen para poder verificar desde la
   consola lo mismo que la pantalla avisa.
   ============================================================================ */

/* 3a. La vista de usuarios, y el control de IDs repetidos. Si alguna vez
       devuelve filas, la clave del usuario de una tarjeta deja de poder ser solo
       el ID y hay que resolverlo ANTES de seguir cargando tarjetas. */
IF OBJECT_ID('dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS') IS NOT NULL
BEGIN
    DECLARE @usuarios INT = (SELECT COUNT(*) FROM dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS);

    PRINT 'Usuarios disponibles para tarjetas: ' + CAST(@usuarios AS VARCHAR(10)) + '.';

    IF EXISTS (SELECT 1 FROM dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS
               GROUP BY ID_DIRECTOR HAVING COUNT(*) > 1)
    BEGIN
        PRINT 'ATENCION: hay IDs REPETIDOS en RO_V_CASHFLOW_USUARIOS_TARJETAS. ID_USUARIO '
            + 'deja de identificar a una persona y dos tarjetas de usuarios distintos pueden '
            + 'quedar apuntando al mismo ID. Se listan abajo.';

        SELECT ID_DIRECTOR, COUNT(*) AS VECES, MIN(NOMBRE) AS UNO, MAX(NOMBRE) AS OTRO
        FROM dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS
        GROUP BY ID_DIRECTOR
        HAVING COUNT(*) > 1
        ORDER BY ID_DIRECTOR;
    END
    ELSE
    BEGIN
        PRINT 'Sin IDs repetidos en la vista de usuarios: ID_USUARIO alcanza como clave.';
    END
END
GO

/* 3b. Las tarjetas cargadas, por tipo. */
DECLARE @tarjetas INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS);
DECLARE @activas  INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS WHERE ACTIVA = 1);

PRINT 'Tarjetas cargadas: ' + CAST(@tarjetas AS VARCHAR(10))
    + ' (' + CAST(@activas AS VARCHAR(10)) + ' activas).';

IF @tarjetas > 0
BEGIN
    SELECT TIPO, COUNT(*) AS TARJETAS, SUM(CASE WHEN ACTIVA = 1 THEN 1 ELSE 0 END) AS ACTIVAS
    FROM dbo.RO_T_CASHFLOW_TARJETAS
    GROUP BY TIPO
    ORDER BY TIPO;
END
GO

/* 3c. Tarjetas cuyo banco NO esta en BANCO. No es un error de este script: es un
       codigo que quedo viejo, y la pantalla lo marca sin dar de baja nada,
       porque decidir eso es de una persona. */
IF OBJECT_ID('dbo.BANCO') IS NOT NULL
BEGIN
    DECLARE @sinBanco INT = (
        SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_TARJETAS t
        WHERE NOT EXISTS (SELECT 1 FROM dbo.BANCO b WHERE b.COD_BANCO = t.COD_BANCO));

    IF @sinBanco > 0
        PRINT 'AVISO: ' + CAST(@sinBanco AS VARCHAR(10)) + ' tarjeta(s) tienen un COD_BANCO '
            + 'que ya no esta en BANCO. Se muestran igual, marcadas, y no se dan de baja solas.';
END
GO

PRINT 'Tarjetas y resumenes listos.';
GO
