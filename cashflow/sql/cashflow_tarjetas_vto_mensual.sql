/* ============================================================================
   MODULO CASHFLOW - TARJETAS PAGOS CORPORATIVOS: VENCIMIENTO EDITADO Y
   ESTIMACIONES MENSUALES
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql, que crea
            RO_T_CASHFLOW_TARJETAS. La tabla de estimaciones tiene una FK contra
            ella: sin ese script este corta con un mensaje y no crea nada.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   Dos tablas de la sub-pestana Tarjetas Pagos Corporativos:

     RO_T_CASHFLOW_TARJETAS_FACTURA_VTO  el vencimiento corregido de una cuota
     RO_T_CASHFLOW_TARJETAS_MENSUAL      las facturas que se repiten todos los
                                          meses (abonos), y su estimacion

   ----------------------------------------------------------------------------
   1. EL VENCIMIENTO EDITADO

   Quien carga en Tango ya no pone en FECHA_VTO el vencimiento real de la
   factura sino EL DEL RESUMEN DE LA TARJETA en el que se va a pagar. Editarlo
   desde la pestana es corregir en que debito cae esa cuota SIN TOCAR TANGO.

   LA CLAVE ES LA CUOTA, NO EL COMPROBANTE: (COD_PROVEE, T_COMP, N_COMP,
   FECHA_VTO_TANGO). Una factura en cuotas tiene una fila por vencimiento en
   getPendientes(), y cada una se corrige por separado. Es distinto del vinculo
   a la tarjeta, que es del comprobante entero.

   LA FECHA EDITADA REEMPLAZA AL FECHA_VTO DE TANGO EN TODA LA LOGICA de la
   pestana: si esta vencida, la reubicacion al proximo pago de la tarjeta, el mes
   de pago, la cobertura y el reemplazo por resumen. SOLO EN ESTA PESTANA: la
   fecha de pago de Cuentas a Pagar Locales no se entera.

   SI TANGO CAMBIA EL VENCIMIENTO de esa cuota, la fila queda sin cuota a la que
   aplicarse: queda inerte, la pestana lo avisa con los comprobantes, y alguien
   la da de baja. No se reapunta sola: el vencimiento nuevo de Tango puede ser
   justamente la correccion.

   EL MOTIVO NO ES OBLIGATORIO, y la FECHA MINIMA ES HOY: la valida el backend.

   ----------------------------------------------------------------------------
   2. LAS ESTIMACIONES MENSUALES

   Marcar una factura como mensual guarda una COPIA: proveedor, comprobante de
   origen, tarjeta, importe, dia del mes y primer mes a proyectar. La copia
   existe porque la factura sale de pendientes cuando se paga, y la estimacion
   tiene que seguir viva.

   EL IMPORTE ES EL DE LA CUOTA MARCADA, antes de imputaciones (IMPORTE_VTO de
   getPendientes()), NO el pendiente: lo que se repite es lo que se debita por
   mes. En una factura de una cuota coincide con el total; en una en cuotas, el
   total multiplicaria el importe por la cantidad de cuotas.

   UNA SOLA VIGENTE POR PROVEEDOR: indice unico filtrado. Marcar otra factura del
   mismo proveedor da de baja la anterior, con confirmacion en pantalla.

   ----------------------------------------------------------------------------
   LAS DOS: SIN BAJAS FISICAS Y CON AUDITORIA COMPLETA

   Deshacer marca VIGENTE = 0 con USUARIO_BAJA y FECHA_BAJA. Las seis columnas de
   auditoria del modulo nacen aca, asi que estas tablas no van en
   sql/cashflow_auditoria_usuario.sql. Las claves llevan Latin1_General_BIN y los
   largos de CPA04, como RO_T_CASHFLOW_TARJETAS_FACTURA: sin eso 'OGNUNE' y
   'OGNUÑE' serian el mismo proveedor aca y dos en Tango.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La pestana se lee igual. Los controles de editar el vencimiento y de marcar
   mensual se dibujan apagados nombrando este archivo, todas las cuotas usan el
   vencimiento de Tango y no hay estimaciones. El tablero no cambia: las dos
   tablas nacen vacias.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se siembra
   ninguna fila. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda dura: sin el maestro de tarjetas no se crea nada. RAISERROR solo no
      corta los lotes que siguen, asi que cada CREATE vuelve a preguntar.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS. Corre primero sql/cashflow_tarjetas.sql: las estimaciones mensuales tienen una FK contra ella. No se creo nada.', 16, 1);
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_TARJETAS_FACTURA_VTO
      El vencimiento corregido de una cuota.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* La cuota: el comprobante, con los tipos y la collation de CPA04, y el
           vencimiento que tiene en Tango. */
        COD_PROVEE      VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)   COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)  COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE         NOT NULL,

        /* El vencimiento que vale en esta pestana. */
        FECHA_VTO       DATE         NOT NULL,

        /* Opcional: corregir una fecha que cargaron con el resumen equivocado no
           necesita explicacion, y pedirla la volveria un tramite. */
        MOTIVO          VARCHAR(300) NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJVTO_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJVTO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJVTO_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_FACTURA_VTO PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_TARJVTO_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO ya existe: no se toca.';
GO

/* UNA CORRECCION VIGENTE POR CUOTA. Filtrado por VIGENTE = 1: el historial son
   las filas dadas de baja, y un UNIQUE comun las prohibiria. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_TARJVTO_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJVTO_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJVTO_VIGENTE (una correccion por cuota).';
END
GO

/* ============================================================================
   2. RO_T_CASHFLOW_TARJETAS_MENSUAL
      Las facturas que se repiten todos los meses, y su estimacion.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* El proveedor, con su razon social COPIADA: la estimacion sigue viva
           cuando la factura ya se pago y salio de pendientes. */
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        RAZON_SOC       VARCHAR(100)  NULL,

        /* El comprobante de origen y su cuota. FECHA_VTO_TANGO identifica la
           cuota; FECHA_VTO_ORIGEN es el vencimiento VIGENTE al marcarla (el
           editado si lo habia), del que salen el dia y el primer mes. */
        T_COMP          VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE          NOT NULL,
        FECHA_VTO_ORIGEN DATE         NOT NULL,

        ID_TARJETA      INT           NOT NULL,

        /* El importe de la cuota marcada antes de imputaciones (IMPORTE_VTO).
           Fijo: sin ajuste por inflacion. */
        IMPORTE         DECIMAL(18,2) NOT NULL,

        /* El dia del mes en que se debita. Se acota al ultimo dia en los meses
           que no lo tienen y NO se corre al habil: ese dia ya es el vencimiento
           del resumen que cargan en Tango. */
        DIA             TINYINT       NOT NULL,

        /* El primer mes que se proyecta, 'YYYY-MM': el siguiente al del
           vencimiento de origen. No hay mes de fin: se corta desmarcando. */
        MES_DESDE       CHAR(7)       NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJMEN_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJMEN_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJMEN_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_MENSUAL PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT FK_RO_T_CF_TARJMEN_TARJETA FOREIGN KEY (ID_TARJETA)
            REFERENCES dbo.RO_T_CASHFLOW_TARJETAS (ID),

        CONSTRAINT CK_RO_T_CF_TARJMEN_DIA CHECK (DIA BETWEEN 1 AND 31),
        CONSTRAINT CK_RO_T_CF_TARJMEN_IMPORTE CHECK (IMPORTE > 0),
        CONSTRAINT CK_RO_T_CF_TARJMEN_MES
            CHECK (MES_DESDE LIKE '[12][0-9][0-9][0-9]-[01][0-9]')
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL ya existe: no se toca.';
GO

/* UNA ESTIMACION VIGENTE POR PROVEEDOR. Es la red contra dos pantallas marcando
   a la vez: la segunda falla en vez de dejar dos estimaciones del mismo abono. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_TARJMEN_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJMEN_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL (COD_PROVEE)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJMEN_VIGENTE (una estimacion por proveedor).';
END
GO

/* ============================================================================
   3. CONTROL
   ============================================================================ */
SELECT 'RO_T_CASHFLOW_TARJETAS_FACTURA_VTO' AS TABLA,
       CASE WHEN OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_FACTURA_VTO', 'U') IS NULL
            THEN 'FALTA' ELSE 'OK' END AS ESTADO
UNION ALL
SELECT 'RO_T_CASHFLOW_TARJETAS_MENSUAL',
       CASE WHEN OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL', 'U') IS NULL
            THEN 'FALTA' ELSE 'OK' END;
GO
