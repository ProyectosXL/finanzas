/* ============================================================================
   CASHFLOW - SALDOS: parametros de los bancos que vienen por Interbanking
   ============================================================================
   Base    : central. Reejecutable.
   Orden   : DESPUES de sql/cashflow_saldos_borrar_bancos_manuales.sql y antes
             de publicar el codigo de feature/saldos-interbanking. Ver
             README-saldos.md, "Ejecucion de los scripts".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   Los saldos bancarios dejan de tipearse: se leen en vivo de
   BI_T_SALDOS_INTERBANKING, que alimenta un proceso externo a este repo (ver
   Class/SaldosInterbanking.php). Ese origen trae el numero de banco y de
   cuenta, pero no dice como quiere el negocio ver cada banco ni cuales se
   siguen operando. Eso es lo que guarda este script, y NADA MAS: los saldos
   no se copian a ninguna tabla propia, porque la de BI ya tiene el historico
   y dos copias del mismo dato terminan discrepando.

   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_SALDOS_BANCO          alias y estado de cada banco (parametro)
     2. RO_T_CASHFLOW_SALDOS_BANCO_MANUAL   saldo cargado a mano como respaldo de
                                            una cuenta que Interbanking no trae
     3. RO_T_CASHFLOW_SALDOS_BANCO_CUENTA   las cuentas ya vistas, para detectar
                                            las nuevas; se siembra con las de hoy

   ----------------------------------------------------------------------------
   UN BANCO SIN FILA ES UN BANCO ACTIVO Y SIN ALIAS
   La lectura NUNCA escribe: un banco que aparece por primera vez en
   Interbanking entra al disponible sin que nadie lo de de alta. La fila se
   crea, como UPSERT, recien cuando alguien guarda alias o estado desde
   Parametros -> Saldos. Si dependiera de un alta, un banco nuevo quedaria
   afuera del disponible en silencio hasta que alguien se diera cuenta.

   LA CLAVE VA CON LA COLLATION DEL ORIGEN
   NRO_BANCO usa Modern_Spanish_CI_AI, igual que la tabla de BI, para que el
   dato compare igual de los dos lados aunque el cruce se haga en PHP.

   NO HAY BAJAS FISICAS: un banco se inhabilita con ACTIVO = 0 y sigue
   viendose en Parametros, para poder reactivarlo.

   Sin este script la pantalla no falla: todos los bancos se muestran activos y
   sin alias, y Parametros dice que script falta.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_SALDOS_BANCO
   Un banco por NRO_BANCO, como lo trae Interbanking ('007', '014'...).

   ALIAS es DEL BANCO, no de cada cuenta: todas sus cuentas lo muestran. Existe
   porque Tango guarda la razon social o un nombre viejo ("RIO DE LA PLATA
   S.A." es Santander, "FRANCES" es BBVA) y el negocio no lo reconoce. NULL
   vuelve a mostrar BANCO.DESC_BANCO; un alias de puros espacios se guarda NULL.

   ACTIVO = 0 saca al banco ENTERO de la pestana y del tablero, y lo calla:
   ni cuentas nuevas, ni saldo viejo, ni saldo faltante. Es para un banco que
   ya no se opera (hoy, Supervielle) y que Interbanking sigue informando.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_BANCO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_BANCO (
        NRO_BANCO     VARCHAR(10)  COLLATE Modern_Spanish_CI_AI NOT NULL,
        ALIAS         VARCHAR(100) COLLATE Modern_Spanish_CI_AI NULL,
        ACTIVO        BIT          NOT NULL CONSTRAINT DF_CF_SAL_BCO_ACTIVO DEFAULT (1),

        USUARIO_ALTA  VARCHAR(50)  NULL,
        FECHA_ALTA    DATETIME     NULL CONSTRAINT DF_CF_SAL_BCO_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50)  NULL,
        FECHA_MODIF   DATETIME     NULL CONSTRAINT DF_CF_SAL_BCO_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA  VARCHAR(50)  NULL,
        FECHA_BAJA    DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_BANCO PRIMARY KEY CLUSTERED (NRO_BANCO)
    );

    PRINT 'Creada RO_T_CASHFLOW_SALDOS_BANCO.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_SALDOS_BANCO ya existia: no se toco.';
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_SALDOS_BANCO_MANUAL
   El saldo de una cuenta de Interbanking cargado A MANO, como respaldo.

   POR QUE EXISTE: cuando Interbanking no trae el saldo contable de una cuenta
   por un error de la integracion (hoy, 014 Provincia), esa plata existe y el
   disponible no la tendria. Se carga desde la pestana Saldos, en la fila de
   la cuenta.

   COMO SE RESUELVE CONTRA INTERBANKING (la regla vive en
   Class/SaldosInterbanking.php, resolverSaldo())
   GANA LA FECHA MAS NUEVA, y A IGUAL FECHA GANA INTERBANKING, que es la fuente
   oficial. Asi, cuando BI se arregla, Interbanking vuelve a mandar solo, sin
   que nadie tenga que quitar el respaldo. Es la regla inversa a la de la caja
   de los locales (alla gana el manual): aca el manual no corrige un dato
   viejo, tapa un dato que falta.

   HISTORIAL SIN BAJAS FISICAS. Reemplazar da de baja el vigente (VIGENTE = 0,
   con usuario y fecha de baja) e inserta el nuevo apuntando al anterior por
   ID_REEMPLAZA, en una transaccion: mismo circuito que los movimientos de
   fondos. Quitar el respaldo da de baja y no inserta nada. Si reemplazar
   solo insertara, el anterior seguiria vigente, y una correccion con fecha
   anterior perderia contra el.

   UN SOLO RESPALDO VIGENTE POR CUENTA, y lo garantiza la base con un indice
   unico filtrado: dos vigentes de la misma cuenta dejarian "cual se usa"
   librado al orden de lectura.

   La cuenta se identifica igual que en el origen -NRO_BANCO, NRO_CUENTA y
   MONEDA- y con su collation. SALDO_CONTABLE lleva el tipo de la columna de
   BI: es el mismo dato, cargado por otra via.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_BANCO_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_BANCO_MANUAL (
        ID             INT IDENTITY(1,1) NOT NULL,
        NRO_BANCO      VARCHAR(10)   COLLATE Modern_Spanish_CI_AI NOT NULL,
        NRO_CUENTA     VARCHAR(50)   COLLATE Modern_Spanish_CI_AI NOT NULL,
        MONEDA         VARCHAR(5)    COLLATE Modern_Spanish_CI_AI NOT NULL,
        FECHA_SALDO    DATE          NOT NULL,
        SALDO_CONTABLE DECIMAL(18,2) NOT NULL,
        OBSERVACION    VARCHAR(500)  NULL,
        VIGENTE        BIT           NOT NULL CONSTRAINT DF_CF_SAL_BCOM_VIGENTE DEFAULT (1),
        ID_REEMPLAZA   INT           NULL,

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NULL CONSTRAINT DF_CF_SAL_BCOM_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)   NULL,
        FECHA_MODIF    DATETIME      NULL CONSTRAINT DF_CF_SAL_BCOM_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA   VARCHAR(50)   NULL,
        FECHA_BAJA     DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_BANCO_MANUAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_BANCO_MANUAL_REEMPLAZA
            FOREIGN KEY (ID_REEMPLAZA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_BANCO_MANUAL (ID)
    );

    /* Un solo respaldo vigente por cuenta. Tambien es el indice de "el
       respaldo vigente de cada cuenta", que corre en cada dibujado de la
       pestana y en cada calculo del tablero. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CASHFLOW_SALDOS_BANCO_MANUAL_VIGENTE
        ON dbo.RO_T_CASHFLOW_SALDOS_BANCO_MANUAL (NRO_BANCO, NRO_CUENTA, MONEDA)
        WHERE VIGENTE = 1;

    PRINT 'Creada RO_T_CASHFLOW_SALDOS_BANCO_MANUAL.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_SALDOS_BANCO_MANUAL ya existia: no se toco.';
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_SALDOS_BANCO_CUENTA
   Las cuentas de Interbanking que alguien ya MARCO COMO VISTAS.

   POR QUE EXISTE: una cuenta nueva en Interbanking entra sola al disponible
   -como cualquier otra de un banco activo-, y eso esta bien: es plata que
   existe. Pero nadie la reviso todavia, y puede ser una cuenta que no se
   esperaba. Una cuenta que no esta en esta tabla es NUEVA: la pestana la marca
   y avisa, y deja de hacerlo cuando alguien la marca como vista en
   Parametros -> Saldos.

   LA FILA EXISTE SOLO SI LA CUENTA YA SE VIO, asi que el alta (USUARIO_ALTA,
   FECHA_ALTA) ES quien y cuando la marco como vista: no hacen falta columnas
   aparte para decir lo mismo. No hay baja: una cuenta vista no vuelve a ser
   nueva.

   SE SIEMBRA CON TODAS LAS CUENTAS QUE YA ESTAN HOY EN BI, con usuario NULL
   (la pantalla las muestra como sembradas por este script). Sin la siembra,
   el primer dia todas aparecerian como nuevas y el aviso no diria nada. Va
   por MERGE WHEN NOT MATCHED: una segunda corrida no duplica nada, y una
   cuenta que llego despues de la primera corrida tambien queda vista, que es
   lo que se espera de correr el script.

   La clave es la del origen -NRO_BANCO, NRO_CUENTA y MONEDA-, con su
   collation. Una cuenta que llega SIN moneda no se siembra: no tiene clave.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_BANCO_CUENTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_BANCO_CUENTA (
        NRO_BANCO     VARCHAR(10) COLLATE Modern_Spanish_CI_AI NOT NULL,
        NRO_CUENTA    VARCHAR(50) COLLATE Modern_Spanish_CI_AI NOT NULL,
        MONEDA        VARCHAR(5)  COLLATE Modern_Spanish_CI_AI NOT NULL,

        USUARIO_ALTA  VARCHAR(50) NULL,
        FECHA_ALTA    DATETIME    NULL CONSTRAINT DF_CF_SAL_BCOC_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50) NULL,
        FECHA_MODIF   DATETIME    NULL CONSTRAINT DF_CF_SAL_BCOC_FMODIF DEFAULT (GETDATE()),

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_BANCO_CUENTA
            PRIMARY KEY CLUSTERED (NRO_BANCO, NRO_CUENTA, MONEDA)
    );

    PRINT 'Creada RO_T_CASHFLOW_SALDOS_BANCO_CUENTA.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_SALDOS_BANCO_CUENTA ya existia: no se toco.';
GO

/* La siembra. Si la tabla de BI no existe en este entorno, no hay nada que
   sembrar y se dice; la pantalla funciona igual. */
IF OBJECT_ID('dbo.BI_T_SALDOS_INTERBANKING', 'U') IS NULL
    PRINT 'No existe BI_T_SALDOS_INTERBANKING: no se siembran cuentas vistas.';
ELSE
BEGIN
    MERGE dbo.RO_T_CASHFLOW_SALDOS_BANCO_CUENTA AS T
    USING (
        SELECT DISTINCT LTRIM(RTRIM(NRO_BANCO)) AS NRO_BANCO,
                        LTRIM(RTRIM(NRO_CUENTA)) AS NRO_CUENTA,
                        UPPER(LTRIM(RTRIM(MONEDA))) AS MONEDA
        FROM dbo.BI_T_SALDOS_INTERBANKING
        WHERE MONEDA IS NOT NULL AND LTRIM(RTRIM(MONEDA)) <> ''
    ) AS S
        ON T.NRO_BANCO = S.NRO_BANCO AND T.NRO_CUENTA = S.NRO_CUENTA AND T.MONEDA = S.MONEDA
    WHEN NOT MATCHED THEN
        INSERT (NRO_BANCO, NRO_CUENTA, MONEDA) VALUES (S.NRO_BANCO, S.NRO_CUENTA, S.MONEDA);

    PRINT 'Cuentas de Interbanking sembradas como vistas: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + '.';
END
GO
