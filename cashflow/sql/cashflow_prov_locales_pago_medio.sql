/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: UN COMPROBANTE PAGADO CON DOS MEDIOS
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales.sql. Se puede correr en
             cualquier momento; sin el, la pantalla funciona igual y avisa.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Una factura puede pagarse con DOS medios: parte con un echeq y parte con una
   transferencia, o con dos echeqs de fechas distintas. La planilla de pagos lo
   dice con dos filas del mismo comprobante, cada una con su FECHA_PAGO, su
   FORMA_PAGO y su IMPORTE. Cada parte entra al eje con su importe y su fecha.

   Esta tabla guarda esa APERTURA: una fila por medio, a lo sumo dos vigentes
   por comprobante.

   ----------------------------------------------------------------------------
   POR QUE UNA TABLA PROPIA Y NO DOS COLUMNAS MAS EN LA DE PAGOS

   RO_T_CASHFLOW_PROV_LOCALES_PAGO es la tabla de los OVERRIDES DEL
   COMPROBANTE -la fecha, la forma con la que se lo trata, si se lo excluye- y
   su clave es el comprobante. La exclusion, el override de forma y la
   observacion siguen viviendo ahi: son decisiones sobre el comprobante entero,
   no sobre una de sus partes. Una apertura son N filas por comprobante, y
   meterla ahi obligaba a romper esa clave o a duplicar columnas "_2".

   EL CASO DE UN MEDIO NO CAMBIA Y NO SE MIGRA NADA. Un comprobante sin filas
   aca es lo que era hasta hoy: una fecha en la tabla de pagos.

   ----------------------------------------------------------------------------
   QUE QUEDA EN LA TABLA DE PAGOS PARA UN COMPROBANTE ABIERTO

   Su FECHA_PAGO es la MAS TARDIA de las dos partes, con FUENTE_FECHA ARCHIVO.
   No es una copia de mas: es lo que hace que la conciliacion -que compara la
   fecha prevista contra la real- mida el desvio contra la ultima parte, y que
   un comprobante cuyo pendiente ya no coincide con la apertura (un pago
   parcial en Tango) se proyecte entero en esa fecha sin codigo nuevo. Lo
   escribe Proveedores::camposDeImportacion(), y hay prueba.

   ----------------------------------------------------------------------------
   LA FORMA ES ECHEQ O TRANSFERENCIA, Y NADA MAS

   Partir un pago tiene sentido para lo que se paga decidiendo cuando: un echeq
   o una transferencia se emiten el dia que alguien elige. Un debito o la caja
   no se parten. El CHECK es la red; la regla con su mensaje esta en
   Proveedores::compararImportacion(), y tests/test_prov_locales_medios.php
   fija que esta lista sea la misma que ProveedoresCategorias::FORMAS_CRONOGRAMA.

   ESTA FORMA NO DECIDE NADA. Si el comprobante entra al cronograma lo sigue
   decidiendo la forma vigente -el override de la factura o el maestro-, igual
   que FORMA_PAGO de la tabla de pagos. Es un hecho: por donde va a salir cada
   parte.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS, CON HISTORIAL

   Reimportar un comprobante reemplaza su apertura ENTERA, y el boton de volver
   al vencimiento la borra: en los dos casos las filas vigentes pasan a
   VIGENTE = 0 con quien y cuando, y la nueva apertura se inserta. Un indice
   unico filtrado por VIGENTE = 1 impide dos partes vigentes con el mismo
   ORDEN. Mismo criterio que RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO.

   ----------------------------------------------------------------------------
   LA CLAVE CON LA COLLATION DE TANGO

   COD_PROVEE, T_COMP y N_COMP en Latin1_General_BIN, como en la tabla de
   pagos y en CPA04: 'OGNUNE' y 'OGNUÑE' son dos proveedores distintos en Tango
   y tienen que serlo aca. Ver sql/cashflow_prov_locales_collation.sql.

   ----------------------------------------------------------------------------
   SIN ESTE SCRIPT

   La importacion rechaza las filas de dos medios con un motivo que nombra este
   archivo, y todo lo demas funciona como hoy. La pestaña avisa que falta.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho. El dia que se corre la
   tabla nace vacia y el tablero no se mueve.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_PAGO. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La tabla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO (
        ID              INT IDENTITY(1,1) NOT NULL,
        /* La clave del comprobante, con la collation de CPA04. */
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,

        /* 1 = la parte de fecha mas temprana. Con la misma fecha, desempata el
           orden de las filas en el archivo. */
        ORDEN           TINYINT       NOT NULL,
        FECHA_PAGO      DATE          NOT NULL,
        FORMA_PAGO      VARCHAR(30)   NOT NULL,
        /* Lo que decia la celda, tal cual: 'echeq' en minuscula, por ejemplo. */
        FORMA_PAGO_ORIG VARCHAR(60)   NULL,
        IMPORTE         DECIMAL(18,2) NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_PLMED_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLMED_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLMED_MODIF DEFAULT (GETDATE()),
        /* Cuando la apertura se reemplazo o se borro. */
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PLMED_ORDEN CHECK (ORDEN IN (1, 2)),
        CONSTRAINT CK_RO_T_CF_PLMED_FORMA CHECK (FORMA_PAGO IN ('ECHEQ', 'TRANSFERENCIA')),
        CONSTRAINT CK_RO_T_CF_PLMED_IMPORTE CHECK (IMPORTE > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA PARTE VIGENTE POR COMPROBANTE Y ORDEN.

   Va como INDICE UNICO FILTRADO y no como constraint: un UNIQUE comun
   prohibiria tambien las filas historicas repetidas, que es justamente lo que
   el historial necesita tener. Es ademas el indice de la lectura de cada carga:
   "las aperturas vigentes".
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_RO_T_CF_PLMED_VIGENTE'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_PLMED_VIGENTE
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO (COD_PROVEE, T_COMP, N_COMP, ORDEN)
        WHERE VIGENTE = 1;
END
GO

PRINT 'Pagos con dos medios listos.';
GO
