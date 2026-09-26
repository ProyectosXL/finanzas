/* ============================================================================
   MODULO CASHFLOW - PESTANA SALDOS
   DDL de las cinco tablas del modulo + semillas
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_SALDOS_
   Orden   : se puede correr en cualquier momento. No depende de los otros
             scripts, aunque comparte RO_T_CASHFLOW_PARAMETROS con Ventas y
             alimenta las filas DISPONIBLE y CAJA_LOCALES que sembro
             sql/cashflow_estructura_disponibilidades.sql.
   ----------------------------------------------------------------------------
   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_SALDOS_CUENTA     catalogo de cuentas (parametro)
     2. RO_T_CASHFLOW_SALDOS_CARGA      cabecera de cada carga (evento fechado)
     3. RO_T_CASHFLOW_SALDOS_DETALLE    saldos por cuenta y fecha (historico)
     4. RO_T_CASHFLOW_SALDOS_SUCURSAL   gestion y reserva por local (parametro)
     5. RO_T_CASHFLOW_SALDOS_LOCAL      saldos de caja de locales (historico)
     5.b RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL  saldo de caja tipeado a mano cuando
                                        la consulta no trajo el cierre
     6. Parametros del modulo en RO_T_CASHFLOW_PARAMETROS

   ----------------------------------------------------------------------------
   LAS TRES PROPIEDADES QUE TIENE QUE CUMPLIR ESTE MODELO

   1. EL HISTORICO NO SE PISA. Una carga es un EVENTO FECHADO, no un UPDATE
      sobre la fila del banco. Cada carga inserta un juego nuevo de filas de
      detalle; las anteriores quedan. Por eso el detalle cuelga de ID_CARGA y no
      tiene una clave (cuenta, fecha) que se sobrescriba.

   2. "LA ULTIMA CARGA" ES UNA PREGUNTA CON RESPUESTA UNICA. La resuelve la
      cabecera:
          SELECT TOP 1 ID FROM RO_T_CASHFLOW_SALDOS_CARGA
          WHERE TIPO = ? AND ACTIVO = 1
          ORDER BY FECHA_CARGA DESC, ID DESC
      Con un MAX(FECHA) sobre el detalle, dos cargas del mismo dia devolverian
      las filas mezcladas de las dos. Con la cabecera, el desempate por ID
      IDENTITY es total y siempre gana la ultima insertada.

      TIPO separa las dos pestanas ('SALDOS' y 'LOCALES'): son dos procesos
      distintos -uno manual y semanal, el otro una consulta diaria- y si
      compartieran cabecera, la ultima carga de una pestana podria ser una
      cabecera sin ninguna fila de la otra.

   3. LOS CAMPOS DE LA API EXISTEN DESDE EL DIA UNO. La integracion con
      Interbanking todavia NO esta hecha: hoy los saldos bancarios se cargan a
      mano. Las columnas que va a devolver la API estan creadas igual y quedan
      en NULL, para que enchufar la integracion no obligue a migrar datos.

      Las columnas que salen del Anexo I llevan EL NOMBRE DE LA API en
      mayusculas (COUNTABLE_BALANCE, BANK_ID, CBU...). Es a proposito: son
      literalmente los campos del proveedor, y nombrarlos igual hace que el
      mapeo del dia de manana sea una copia uno a uno, sin tabla de traduccion
      que se desincronice. Las columnas propias del modulo van en espanol
      (ID_CARGA, ORIGEN_DATO, FECHA_UPDATE...). El mapeo campo por campo esta en
      README-saldos.md.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE
   Las tablas se crean solo si no existen y las semillas entran por MERGE
   WHEN NOT MATCHED, asi que una segunda corrida no duplica nada ni pisa un
   valor ya editado por el usuario. La pantalla tampoco falla si el script no se
   corrio: muestra un aviso, igual que hace hoy el tablero con la estructura.

   NO HAY BAJAS FISICAS: todo se inhabilita con ACTIVO = 0.

   Todas las tablas llevan USUARIO VARCHAR(50) NULL. Todavia no hay login, por
   lo que se graba NULL; los metodos de guardado de PHP ya reciben $usuario.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_SALDOS_CUENTA
   Catalogo de cuentas. Es un PARAMETRO: se administra desde
   Parametros -> Saldos y no se carga en cada actualizacion de saldos.

   TIPO dice de donde sale el saldo de esa cuenta:
       'BANCO'            -> cuenta bancaria (hoy manual, manana API)
       'MERCADO_PAGO'     -> billetera (manual)
       'EFECTIVO_CENTRAL' -> caja de tesoreria de casa central; su saldo NO se
                             tipea, sale de la consulta sobre SBA05
       'OTRO'             -> lo que aparezca despues

   ORIGEN_DATO dice COMO se llena el saldo:
       'MANUAL'   -> lo tipea una persona en la pestana Saldos
       'API'      -> lo trae Interbanking (todavia no existe)
       'CONSULTA' -> lo resuelve una consulta del sistema (el efectivo central)

   Los siete campos de /accounts (Consulta de Cuentas) del Anexo I estan todos:
   BANK_ID, BANK_NAME, ACCOUNT_NUMBER, ACCOUNT_TYPE, CBU, ACCOUNT_LABEL y
   currency, que es MONEDA.

   POR QUE MONEDA Y NO CURRENCY: es el unico campo de /accounts que el modulo ya
   necesita hoy -la pestana cierra con un total en pesos y otro en dolares, y el
   proveedor convierte segun el- asi que se le deja el nombre del dominio y no
   el del proveedor. Es una sola columna, no dos: dos columnas para el mismo
   dato terminan discrepando.

   POR QUE NOMBRE ADEMAS DE BANK_NAME Y ACCOUNT_LABEL: NOMBRE es la etiqueta que
   se ve en pantalla y la controla el usuario. BANK_NAME y ACCOUNT_LABEL son
   strings del proveedor, que puede cambiarlos de su lado sin avisar. Cuando
   entre la API, una cuenta nueva nace con NOMBRE = BANK_NAME y el usuario puede
   renombrarla sin que la proxima sincronizacion le pise el nombre.

   UNA MISMA ENTIDAD PUEDE TENER SALDOS EN LAS DOS MONEDAS, asi que la clave
   natural incluye la moneda: (TIPO, NOMBRE, MONEDA).

   El CBU es la clave con la que la API va a reconocer una cuenta ya cargada a
   mano. Va con un indice unico FILTRADO porque hoy casi todas las filas lo
   tienen en NULL, y un UNIQUE comun de SQL Server admite un solo NULL.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_CUENTA (
        ID             INT IDENTITY(1,1) NOT NULL,
        TIPO           VARCHAR(20)   NOT NULL,
        NOMBRE         VARCHAR(80)   NOT NULL,
        MONEDA         CHAR(3)       NOT NULL CONSTRAINT DF_CF_SAL_CTA_MON  DEFAULT ('ARS'),
        ORIGEN_DATO    VARCHAR(10)   NOT NULL CONSTRAINT DF_CF_SAL_CTA_ORIG DEFAULT ('MANUAL'),

        /* -- Campos de /accounts (Anexo I). Hoy en NULL: los llena la API. -- */
        BANK_ID        VARCHAR(3)    NULL,   -- codigo BCRA, 3 digitos
        BANK_NAME      VARCHAR(80)   NULL,
        ACCOUNT_NUMBER VARCHAR(30)   NULL,
        ACCOUNT_TYPE   VARCHAR(2)    NULL,   -- 'CC' cuenta corriente / 'CA' caja de ahorro
        CBU            VARCHAR(22)   NULL,
        ACCOUNT_LABEL  VARCHAR(80)   NULL,

        ORDEN          INT           NOT NULL CONSTRAINT DF_CF_SAL_CTA_ORDEN  DEFAULT (0),
        ACTIVO         BIT           NOT NULL CONSTRAINT DF_CF_SAL_CTA_ACTIVO DEFAULT (1),
        FECHA_UPDATE   DATETIME      NOT NULL CONSTRAINT DF_CF_SAL_CTA_FUPD   DEFAULT (GETDATE()),
        USUARIO        VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_CUENTA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_SALDOS_CUENTA UNIQUE (TIPO, NOMBRE, MONEDA),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_TIPO
            CHECK (TIPO IN ('BANCO', 'MERCADO_PAGO', 'EFECTIVO_CENTRAL', 'OTRO')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_MONEDA
            CHECK (MONEDA IN ('ARS', 'USD')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_ORIGEN
            CHECK (ORIGEN_DATO IN ('API', 'MANUAL', 'CONSULTA')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_ACCTYPE
            CHECK (ACCOUNT_TYPE IS NULL OR ACCOUNT_TYPE IN ('CC', 'CA'))
    );

    /* El CBU identifica a la cuenta del lado de Interbanking. Filtrado porque
       hoy la mayoria de las filas lo tienen en NULL. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CASHFLOW_SALDOS_CUENTA_CBU
        ON dbo.RO_T_CASHFLOW_SALDOS_CUENTA (CBU)
        WHERE CBU IS NOT NULL;
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_SALDOS_CARGA
   Cabecera de una carga. Es lo que convierte "la ultima carga" en UNA FILA y no
   en un MAX(FECHA) que se rompe cuando dos cargas caen el mismo dia.

   TIPO separa las dos pestanas:
       'SALDOS'  -> pestana 1: efectivo central + bancos + Mercado Pago
       'LOCALES' -> pestana 2: caja de los locales propios

   ORIGEN dice como se genero la carga. 'MIXTA' es el caso normal de la pestana
   1 mientras la API no exista: el efectivo central sale de una consulta y el
   resto lo tipea una persona.

   FECHA_CARGA lleva HORA, no solo fecha: la carga es semanal pero nada impide
   dos el mismo dia, y sin la hora el orden entre ellas dependeria del ID solo.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_CARGA (
        ID            INT IDENTITY(1,1) NOT NULL,
        TIPO          VARCHAR(15)  NOT NULL,
        FECHA_CARGA   DATETIME     NOT NULL CONSTRAINT DF_CF_SAL_CAR_FECHA  DEFAULT (GETDATE()),
        ORIGEN        VARCHAR(10)  NOT NULL CONSTRAINT DF_CF_SAL_CAR_ORIGEN DEFAULT ('MANUAL'),
        OBSERVACIONES VARCHAR(500) NULL,
        ACTIVO        BIT          NOT NULL CONSTRAINT DF_CF_SAL_CAR_ACTIVO DEFAULT (1),
        FECHA_UPDATE  DATETIME     NOT NULL CONSTRAINT DF_CF_SAL_CAR_FUPD   DEFAULT (GETDATE()),
        USUARIO       VARCHAR(50)  NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_CARGA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CARGA_TIPO
            CHECK (TIPO IN ('SALDOS', 'LOCALES')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CARGA_ORIGEN
            CHECK (ORIGEN IN ('API', 'MANUAL', 'CONSULTA', 'MIXTA'))
    );

    /* Es el indice de "dame la ultima carga de esta pestana", que corre en cada
       dibujado de la pantalla y en cada calculo del tablero. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_CARGA_ULTIMA
        ON dbo.RO_T_CASHFLOW_SALDOS_CARGA (TIPO, ACTIVO, FECHA_CARGA DESC, ID DESC);
END
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_SALDOS_DETALLE
   El historico de saldos, por cuenta y por carga. Pestana 1.

   FECHA_SALDO es la fecha A LA QUE CORRESPONDE EL SALDO, que no es la fecha en
   que se cargo (esa esta en la cabecera). Mapea contra dos campos distintos del
   Anexo I segun de donde venga la fila:
       de /accounts/balances  -> row_date
       de historical_balances -> operation_date
   Por eso lleva nombre propio y no el de ninguno de los dos.

   LOS CINCO SALDOS DE 'balances' ESTAN TODOS, aunque el Cashflow use uno solo.
   El que alimenta el tablero es COUNTABLE_BALANCE, el saldo contable. Los otros
   cuatro son cuatro columnas y evitan una migracion el dia que alguien quiera
   mirar el proyectado a 24 o 48 horas.

   MESSAGE es el error POR CUENTA. La API responde 200 con cuentas que fallaron
   individualmente, asi que ese texto hay que guardarlo: si se descarta, una
   cuenta sin saldo se lee como saldo cero, que es exactamente el error caro que
   este modulo tiene que evitar. Una fila con MESSAGE y los importes en NULL
   significa "esta cuenta no se pudo leer", que no es lo mismo que "esta cuenta
   tiene cero".

   MONEDA se copia de la cuenta en el momento de la carga y no se lee por JOIN:
   si manana alguien corrige la moneda de una cuenta, el historico ya cargado
   tiene que seguir diciendo en que moneda estaba ese importe.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DETALLE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_DETALLE (
        ID          INT IDENTITY(1,1) NOT NULL,
        ID_CARGA    INT           NOT NULL,
        ID_CUENTA   INT           NOT NULL,
        FECHA_SALDO DATE          NOT NULL,
        MONEDA      CHAR(3)       NOT NULL,

        /* -- Los cinco saldos de 'balances' (Anexo I) --------------------- */
        COUNTABLE_BALANCE         DECIMAL(19,4) NULL,  -- el que alimenta el Cashflow
        INITIAL_OPERATING_BALANCE DECIMAL(19,4) NULL,
        CURRENT_OPERATING_BALANCE DECIMAL(19,4) NULL,
        PROJECTED_BALANCE_24HS    DECIMAL(19,4) NULL,
        PROJECTED_BALANCE_48HS    DECIMAL(19,4) NULL,

        /* -- De 'historical_balances' (hasta 180 dias) -------------------- */
        DAY_BALANCE   DECIMAL(19,4) NULL,
        TOTAL_DEBITS  DECIMAL(19,4) NULL,
        TOTAL_CREDITS DECIMAL(19,4) NULL,

        /* -- Error por cuenta que devuelve la API en una respuesta 200 ---- */
        MESSAGE     VARCHAR(500)  NULL,

        ORIGEN_DATO  VARCHAR(10)  NOT NULL CONSTRAINT DF_CF_SAL_DET_ORIGEN DEFAULT ('MANUAL'),
        FECHA_UPDATE DATETIME     NOT NULL CONSTRAINT DF_CF_SAL_DET_FUPD   DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50)  NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_DETALLE PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_SALDOS_DETALLE UNIQUE (ID_CARGA, ID_CUENTA, FECHA_SALDO),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_DETALLE_CARGA
            FOREIGN KEY (ID_CARGA)  REFERENCES dbo.RO_T_CASHFLOW_SALDOS_CARGA (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_DETALLE_CUENTA
            FOREIGN KEY (ID_CUENTA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_CUENTA (ID),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_DETALLE_MONEDA
            CHECK (MONEDA IN ('ARS', 'USD')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_DETALLE_ORIGEN
            CHECK (ORIGEN_DATO IN ('API', 'MANUAL', 'CONSULTA'))
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_DETALLE_CARGA
        ON dbo.RO_T_CASHFLOW_SALDOS_DETALLE (ID_CARGA)
        INCLUDE (ID_CUENTA, FECHA_SALDO, MONEDA, COUNTABLE_BALANCE, MESSAGE);

    /* La serie historica de una cuenta, que es lo que va a consultar el dia que
       se carguen los 180 dias de historical_balances. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_DETALLE_CUENTA_FECHA
        ON dbo.RO_T_CASHFLOW_SALDOS_DETALLE (ID_CUENTA, FECHA_SALDO DESC);
END
GO

/* ----------------------------------------------------------------------------
   4. RO_T_CASHFLOW_SALDOS_SUCURSAL
   Gestion y reserva de caja por local. Es un PARAMETRO: se administra desde
   Parametros -> Saldos y son los valores por defecto de la pestana 2.

   GESTION dice que hace la sucursal con su efectivo:
       'DEPOSITA' -> lo deposita en el banco. ENTRA al cashflow.
       'ENVIA'    -> lo manda a casa central por otra via. Se muestra en la
                     pantalla pero NO entra a la serie: su efectivo no llega al
                     banco por esta via, y sumarlo seria contar plata que el
                     tablero no va a ver acreditada.

   RESERVA es el minimo que la sucursal debe conservar en caja. El neto a
   depositar es saldo menos reserva, SIN ningun ajuste impositivo (ver la nota
   en Class/Saldos.php).

   La lista de sucursales se sincroniza desde SUCURSALES_LAKERS (CANAL='PROPIOS'
   y HABILITADO=1). La sincronizacion NUNCA pisa GESTION ni RESERVA de una fila
   que ya existe, y a las sucursales que desaparecen del origen las marca
   ACTIVO = 0 en lugar de borrarlas.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL (
        NRO_SUCURSAL  INT           NOT NULL,
        DESC_SUCURSAL VARCHAR(80)   NOT NULL,
        GESTION       VARCHAR(10)   NOT NULL CONSTRAINT DF_CF_SAL_SUC_GEST    DEFAULT ('DEPOSITA'),
        RESERVA       DECIMAL(19,4) NOT NULL CONSTRAINT DF_CF_SAL_SUC_RESERVA DEFAULT (0),
        ACTIVO        BIT           NOT NULL CONSTRAINT DF_CF_SAL_SUC_ACTIVO  DEFAULT (1),
        FECHA_UPDATE  DATETIME      NOT NULL CONSTRAINT DF_CF_SAL_SUC_FUPD    DEFAULT (GETDATE()),
        USUARIO       VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_SUCURSAL PRIMARY KEY CLUSTERED (NRO_SUCURSAL),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_SUCURSAL_GESTION
            CHECK (GESTION IN ('DEPOSITA', 'ENVIA')),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_SUCURSAL_RESERVA
            CHECK (RESERVA >= 0)
    );
END
GO

/* ----------------------------------------------------------------------------
   5. RO_T_CASHFLOW_SALDOS_LOCAL
   Historico de la caja de los locales. Pestana 2.

   GESTION y RESERVA se guardan EFECTIVAS DE CADA CARGA y no solo como
   parametro vigente. Es lo que permite reconstruir una carga vieja despues de
   que alguien cambie la reserva de una sucursal: sin esto, recalcular una carga
   del mes pasado con la reserva de hoy daria un neto que nunca existio.

   NETO_DEPOSITAR se guarda calculado y no derivado en la consulta por el mismo
   motivo: es el numero que efectivamente entro al tablero ese dia.

   Un neto NEGATIVO se guarda como tal -es informacion: la caja quedo por debajo
   de la reserva-, pero aporta CERO al cashflow. La sucursal no le manda plata
   al banco por tener poca caja. El recorte a cero lo hace Class/Saldos.php y
   esta cubierto por las pruebas.

   FECHA_SALDO es la fecha que devuelve la consulta de Tango, sin corrimiento a
   dia habil ni tratamiento de feriados: cuando la sucursal deposita, el
   movimiento queda registrado, y como la consulta corre todos los dias el dato
   se actualiza solo.

   GRANO: una fila POR SUCURSAL, no por cuenta de tesoreria. La reserva es un
   minimo de la sucursal, asi que el neto solo tiene sentido sobre el total de
   su caja. Cuando una sucursal tiene mas de una cuenta de tesoreria, los saldos
   se suman, CUENTAS dice cuantas se sumaron y COD_CTA_CUENTA_TESORERIA guarda
   los codigos separados por coma, para que la suma sea auditable.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL (
        ID                       INT IDENTITY(1,1) NOT NULL,
        ID_CARGA                 INT           NOT NULL,
        NRO_SUCURSAL             INT           NOT NULL,
        DESC_SUCURSAL            VARCHAR(80)   NOT NULL,
        FECHA_SALDO              DATE          NOT NULL,
        COD_CTA_CUENTA_TESORERIA VARCHAR(60)   NOT NULL CONSTRAINT DF_CF_SAL_LOC_CTA DEFAULT (''),
        CUENTAS                  INT           NOT NULL CONSTRAINT DF_CF_SAL_LOC_CTAS DEFAULT (1),
        SALDO_MONEDA             DECIMAL(19,4) NOT NULL,
        GESTION                  VARCHAR(10)   NOT NULL,
        RESERVA                  DECIMAL(19,4) NOT NULL,
        NETO_DEPOSITAR           DECIMAL(19,4) NOT NULL,
        FECHA_UPDATE             DATETIME      NOT NULL CONSTRAINT DF_CF_SAL_LOC_FUPD DEFAULT (GETDATE()),
        USUARIO                  VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_LOCAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_SALDOS_LOCAL UNIQUE (ID_CARGA, NRO_SUCURSAL),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_LOCAL_CARGA
            FOREIGN KEY (ID_CARGA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_CARGA (ID),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_GESTION
            CHECK (GESTION IN ('DEPOSITA', 'ENVIA'))
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_LOCAL_CARGA
        ON dbo.RO_T_CASHFLOW_SALDOS_LOCAL (ID_CARGA)
        INCLUDE (NRO_SUCURSAL, FECHA_SALDO, SALDO_MONEDA, GESTION, RESERVA, NETO_DEPOSITAR);
END
GO

/* ----------------------------------------------------------------------------
   5.b RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL y ORIGEN_DATO en la foto
   Saldo de caja de un local cargado A MANO, para cuando la alimentacion de
   RO_T_SALDOS_CIERRE_SBA29 falla y el ultimo registro del local queda viejo.

   La regla de precedencia vive en Class/Saldos.php: gana el mas nuevo por
   FECHA_SALDO entre la consulta y el manual, y a igual fecha gana el manual.
   FECHA_SALDO del manual es AYER respecto del dia en que se carga: es el
   cierre que no llego. Insert-only, sin bajas fisicas.

   Mismo bloque que sql/cashflow_saldos_local_manual.sql, que es la migracion
   para las bases donde este script ya corrio. Alcanza con correr uno.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL (
        ID            INT IDENTITY(1,1) NOT NULL,
        NRO_SUCURSAL  INT           NOT NULL,
        FECHA_SALDO   DATE          NOT NULL,
        SALDO_MONEDA  DECIMAL(19,4) NOT NULL,
        SALDO_CONSULTA DECIMAL(19,4) NULL,
        FECHA_CONSULTA DATE          NULL,
        OBSERVACIONES VARCHAR(500)  NULL,
        ACTIVO        BIT           NOT NULL CONSTRAINT DF_CF_SAL_LOCM_ACTIVO DEFAULT (1),
        FECHA_UPDATE  DATETIME      NOT NULL CONSTRAINT DF_CF_SAL_LOCM_FUPD   DEFAULT (GETDATE()),
        USUARIO       VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL PRIMARY KEY CLUSTERED (ID)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL_ULTIMO
        ON dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL (NRO_SUCURSAL, ACTIVO, FECHA_SALDO DESC, ID DESC)
        INCLUDE (SALDO_MONEDA, FECHA_UPDATE, USUARIO);
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'ORIGEN_DATO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL
        ADD ORIGEN_DATO VARCHAR(10) NOT NULL
            CONSTRAINT DF_CF_SAL_LOC_ORIGEN DEFAULT ('CONSULTA'),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_ORIGEN
            CHECK (ORIGEN_DATO IN ('CONSULTA', 'MANUAL'));
END
GO

/* ----------------------------------------------------------------------------
   6. Semilla de la cuenta de efectivo central
   Es la unica cuenta que el modulo puede sembrar: su saldo no lo tipea nadie,
   sale de la consulta sobre SBA05 que define el parametro
   saldos_cta_tesoreria. Los bancos y Mercado Pago los da de alta el usuario
   desde Parametros, porque son datos de la empresa y no del sistema.
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_SALDOS_CUENTA AS T
USING (VALUES
    ('EFECTIVO_CENTRAL', 'Efectivo Tesoreria Casa Central', 'ARS', 'CONSULTA', 10)
) AS S (TIPO, NOMBRE, MONEDA, ORIGEN_DATO, ORDEN)
    ON T.TIPO = S.TIPO AND T.NOMBRE = S.NOMBRE AND T.MONEDA = S.MONEDA
WHEN NOT MATCHED BY TARGET THEN
    INSERT (TIPO, NOMBRE, MONEDA, ORIGEN_DATO, ORDEN, ACTIVO)
    VALUES (S.TIPO, S.NOMBRE, S.MONEDA, S.ORIGEN_DATO, S.ORDEN, 1);
GO

/* ----------------------------------------------------------------------------
   7. Parametros del modulo
   Van en la tabla generica RO_T_CASHFLOW_PARAMETROS que ya usan Ventas y Comex.

   saldos_cta_tesoreria es un NUMERO DE CUENTA CONTABLE, no una constante del
   codigo: cambiarlo no puede requerir tocar un archivo PHP. La consulta lo pasa
   como parametro.

   saldos_dias_alerta_carga es cada cuantos dias se considera vieja la ultima
   carga de saldos. La carga es semanal, asi que el valor inicial es 7: pasados
   esos dias la pantalla y el tablero avisan que el disponible que se esta
   mirando no es de hoy.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NOT NULL
BEGIN
    MERGE dbo.RO_T_CASHFLOW_PARAMETROS AS T
    USING (VALUES
        ('saldos_cta_tesoreria', '100101', 'TEXT',
         'Cuenta contable de SBA05 con el efectivo de la caja de tesoreria de casa central',
         'SALDOS', 'GENERAL'),
        ('saldos_dias_alerta_carga', '7', 'INT',
         'Dias desde la ultima carga a partir de los cuales se avisa que el saldo esta desactualizado',
         'SALDOS', 'GENERAL')
    ) AS S (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
        ON T.CLAVE = S.CLAVE
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
        VALUES (S.CLAVE, S.VALOR, S.TIPO_DATO, S.DESCRIPCION, S.MODULO, S.GRUPO);
END
GO

PRINT 'Modulo Saldos: tablas y semillas listas.';
GO
