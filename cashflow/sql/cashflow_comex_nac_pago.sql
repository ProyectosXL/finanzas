/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: PAGO PARCIAL DE LA NACIONALIZACION
   Crea los pagos parciales y la fecha del saldo de cada contenedor
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_COMEX_NAC_
   Orden   : despues de sql/cashflow_auditoria_usuario.sql (las tablas nacen con
             su esquema de auditoria). No depende de ningun otro. Es el 42 de la
             instalacion completa (README-cashflow.md).
             Sin el, Crono Nacionalizacion funciona exactamente como hoy: no
             dibuja el boton "Pago parcial", no muestra los contenedores con
             costos reales y avisa que script falta.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Hasta aca la nacionalizacion era todo o nada: el tilde "Pagado" saca el
   importe entero de la proyeccion. Pero se puede pagar una parte y quedar un
   saldo. Este script guarda esos pagos y la fecha en la que se espera pagar
   lo que falta, y con eso Crono proyecta el SALDO:

       saldo = base - suma de los pagos parciales vigentes   (nunca negativo)

   con la base en pesos: el costo real de Comercio Exterior si ya lo cargaron
   (la suma de IMPORTE_$ de RO_T_IMPORTACIONES_DETALLE), o la estimacion
   valuada con la curva ROFEX si no. Las reglas, las series del tablero y por
   que la base real y la estimada no tienen la misma composicion estan en
   Class/Comex.php y en README-comex.md, seccion 13.

   ----------------------------------------------------------------------------
   EL DATO ES DEL CASHFLOW Y NO SE ESCRIBE EN COMERCIO EXTERIOR

   Igual que el tilde de RO_T_CASHFLOW_COMEX_PAGADO: es una afirmacion del
   cashflow sobre su propia proyeccion. Comercio Exterior no tiene el concepto
   de pago parcial de la nacionalizacion, y este modulo no le agrega nada a su
   maestro. Si algun dia lo quiere ver, ID_MG es la clave de su contenedor.

   ----------------------------------------------------------------------------
   ID_MG ES SIEMPRE EL CONTENEDOR: LA OC PRINCIPAL

   Un contenedor puede tener varias ordenes de compra (OC principal + hijas,
   ID_PADRE en el maestro de Comex). La base, los pagos y la fecha del saldo
   tienen que hablar del MISMO contenedor, asi que se graban contra la OC
   principal, COALESCE(ID_PADRE, ID), resuelta por el servidor.

   Y SE VUELVE A RESOLVER AL LEER, no solo al grabar: si una OC que era
   principal cuando se cargo el pago pasa despues a ser hija de otra en
   Comercio Exterior, el pago sigue apareciendo en el contenedor. Por eso
   ninguna consulta compara ID_MG contra la fila: siempre pasa por el maestro.

   ----------------------------------------------------------------------------
   NO HAY BAJAS FISICAS

   Anular un pago marca VIGENTE = 0 y sella USUARIO_BAJA / FECHA_BAJA: queda en
   el historial, que es lo que explica por que el saldo de la semana pasada era
   otro. Cambiar la fecha del saldo da de baja la vigente e inserta una nueva,
   con la anterior en FECHA_ANTERIOR.

   SIN FK CONTRA RO_T_IMPORTACIONES_ENCABEZADO, igual que el resto de las tablas
   de este modulo contra las de otras plataformas: si un contenedor se depura
   alla, una FK nuestra haria fallar una depuracion que no es nuestra.

   El dia que se corre este script las tablas nacen vacias y EL TABLERO NO SE
   MUEVE NI UN PESO: sin pagos parciales todo se proyecta como hoy.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS PAGOS PARCIALES
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* La OC principal del contenedor al grabar. Ver el encabezado. */
        ID_MG          INT            NOT NULL,
        /* Cuando se pago. Hoy o antes: una fecha futura no es un pago. */
        FECHA_PAGO     DATE           NOT NULL,
        MONEDA         CHAR(3)        NOT NULL,
        /* En dolares, lo que se pago y con que cotizacion. NULL en pesos. Se
           guardan y se muestran: el equivalente en pesos solo no dice como se
           llego a el. */
        IMPORTE_USD    DECIMAL(18,2)  NULL,
        COTIZACION     DECIMAL(12,4)  NULL,
        /* Lo que se resta del saldo, SIEMPRE en pesos. En dolares lo calcula el
           servidor (IMPORTE_USD x COTIZACION, redondeado al centavo). */
        IMPORTE_ARS    DECIMAL(18,2)  NOT NULL,
        OBSERVACION    VARCHAR(200)   NULL,
        VIGENTE        BIT            NOT NULL
            CONSTRAINT DF_RO_T_CF_NACPAGO_VIGENTE DEFAULT (1),
        USUARIO_ALTA   VARCHAR(50)    NULL,
        FECHA_ALTA     DATETIME       NOT NULL
            CONSTRAINT DF_RO_T_CF_NACPAGO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)    NULL,
        FECHA_MODIF    DATETIME       NULL,
        USUARIO_BAJA   VARCHAR(50)    NULL,
        FECHA_BAJA     DATETIME       NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_NAC_PAGO PRIMARY KEY CLUSTERED (ID),
        /* Las reglas van tambien en la base: el endpoint es alcanzable sin pasar
           por el dialogo. */
        CONSTRAINT CK_RO_T_CF_NACPAGO_MONEDA CHECK (MONEDA IN ('ARS', 'USD')),
        CONSTRAINT CK_RO_T_CF_NACPAGO_IMPORTE CHECK (IMPORTE_ARS > 0),
        CONSTRAINT CK_RO_T_CF_NACPAGO_USD CHECK (
            (MONEDA = 'ARS' AND IMPORTE_USD IS NULL AND COTIZACION IS NULL)
            OR (MONEDA = 'USD' AND IMPORTE_USD > 0 AND COTIZACION > 0))
    );

    /* Lo que corre en cada carga de Crono y del tablero: los vigentes, por
       contenedor. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_NACPAGO_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO (VIGENTE, ID_MG)
        INCLUDE (IMPORTE_ARS);

    PRINT 'Creada dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. LA FECHA DEL SALDO, CON HISTORIAL

   Una fila por cambio; la vigente es la que manda. NO lleva indice unico por
   ID_MG: si dos OC que eran principales quedan en el mismo contenedor, el
   contenedor tiene dos vigentes hasta que alguien cambie la fecha, y el lector
   toma la mas reciente. Prohibirlo haria fallar una reagrupacion que se hace
   en otra aplicacion.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA (
        ID             INT IDENTITY(1,1) NOT NULL,
        ID_MG          INT       NOT NULL,
        FECHA_SALDO    DATE      NOT NULL,
        /* La que decia antes, para leer el historial sin ordenar filas. */
        FECHA_ANTERIOR DATE      NULL,
        VIGENTE        BIT       NOT NULL
            CONSTRAINT DF_RO_T_CF_NACSALDO_VIGENTE DEFAULT (1),
        USUARIO_ALTA   VARCHAR(50) NULL,
        FECHA_ALTA     DATETIME  NOT NULL
            CONSTRAINT DF_RO_T_CF_NACSALDO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50) NULL,
        FECHA_MODIF    DATETIME  NULL,
        USUARIO_BAJA   VARCHAR(50) NULL,
        FECHA_BAJA     DATETIME  NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA PRIMARY KEY CLUSTERED (ID)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_NACSALDO_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA (VIGENTE, ID_MG)
        INCLUDE (FECHA_SALDO, FECHA_ALTA);

    PRINT 'Creada dbo.RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   3. Control final. En una instalacion nueva devuelve todo en cero, y eso es lo
   correcto: las tablas nacen vacias y el tablero no se mueve.
   ---------------------------------------------------------------------------- */
SELECT COUNT(*) AS PAGOS,
       SUM(CASE WHEN VIGENTE = 1 THEN 1 ELSE 0 END) AS VIGENTES,
       ISNULL(SUM(CASE WHEN VIGENTE = 1 THEN IMPORTE_ARS END), 0) AS PESOS_VIGENTES
FROM dbo.RO_T_CASHFLOW_COMEX_NAC_PAGO;
GO

PRINT 'Comercio Exterior: ya se pueden registrar pagos parciales de la nacionalizacion.';
GO
