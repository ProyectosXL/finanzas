/* ============================================================================
   MODULO CASHFLOW - COMPRAS EXTERIOR: LOS INSUMOS MATERIALIZADOS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ANTES de sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql y de
            sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql, que llenan estas
            tablas. Despues de sql/cashflow_compras_proyectadas.sql, aunque no
            depende de el.
   ----------------------------------------------------------------------------
   QUE CREA

   1. RO_T_CASHFLOW_JOB_LOG               una fila por corrida de cada SP
   2. RO_T_CASHFLOW_COMEX_RECEP_HIST      la historia de recepciones, por mes
   3. RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN  el presupuesto oficial, por temporada
   4. RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE cuanto filtra la vista del presupuesto

   POR QUE EXISTEN

   Medido contra la base el 24/09/2026: la pestana Comercio Exterior >
   Proyeccion tardaba entre 34 y 40 s y la fila del tablero entre 31 y 33 s, y
   de eso entre 38 y 57 s por llamada era UNA consulta, la historia de
   recepciones sobre STA20 (11,9 millones de filas). Todo lo demas junto -el
   presupuesto, el contraste, lo ya comprado, los ajustes, la curva- sumaba
   alrededor de un segundo, y el calculo en PHP medio milisegundo.

   La historia es historia: no cambia de un dia para el otro. El presupuesto
   oficial cambia cuando alguien marca una version, que es un evento de la
   app de compras. Ninguno de los dos tiene por que leerse en cada pedido.

   SOLO SE MATERIALIZAN LOS INSUMOS. La cuenta -ventana, cuota, descuento de
   lo ya comprado, ajustes, valuacion- sigue en PHP, en ComprasProyectadas,
   con sus pruebas. Duplicarla en SQL dejaria dos definiciones del mismo
   numero. Y lo ya comprado, los ajustes y la curva se siguen leyendo en vivo:
   un contenedor cargado tiene que descontar en el momento.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   Las filas proyectadas del tablero van en CERO y el primer aviso de la fila
   y de la pestana dice que se esta proyectando DE MENOS y que job falta
   correr. NO hay vuelta a la consulta en vivo: con 40 segundos de lectura el
   tablero entero queda esperando, y un fallback silencioso esconderia que el
   job no corre.

   ES REEJECUTABLE: cada tabla y cada indice preguntan por OBJECT_ID antes de
   crearse. La segunda corrida no modifica nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. EL LOG DE LAS CORRIDAS

   Cada SP inserta su fila al EMPEZAR -con FIN en NULL- y la completa al
   terminar, con FILAS si salio bien o con ERROR si no. La fila de inicio va
   FUERA de la transaccion del SP a proposito: si la corrida se cae, el
   intento queda registrado igual. Una corrida con FIN en NULL y sin ERROR es
   una que todavia esta en curso, o una que se corto sin llegar al CATCH.

   USUARIO es quien la pidio: el login del SQL Agent para el job programado,
   o el usuario de la pantalla cuando se aprieta "Actualizar ahora".

   La pestana lee de aca el "Historia al dd/mm hh:mm" y el "Presupuesto al
   dd/mm hh:mm": la ultima corrida SIN ERROR de cada proceso.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_JOB_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_JOB_LOG (
        ID       INT IDENTITY(1,1) NOT NULL,
        PROCESO  VARCHAR(60)    NOT NULL,
        INICIO   DATETIME       NOT NULL
            CONSTRAINT DF_RO_T_CF_JOBLOG_INICIO DEFAULT (GETDATE()),
        FIN      DATETIME       NULL,
        FILAS    INT            NULL,
        ERROR    NVARCHAR(4000) NULL,
        USUARIO  VARCHAR(128)   NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_JOB_LOG PRIMARY KEY CLUSTERED (ID)
    );

    /* "La ultima corrida de X" es la unica pregunta que se le hace. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_JOBLOG_PROCESO
        ON dbo.RO_T_CASHFLOW_JOB_LOG (PROCESO, INICIO DESC)
        INCLUDE (FIN, FILAS, USUARIO);

    PRINT 'RO_T_CASHFLOW_JOB_LOG creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_JOB_LOG ya existia.';
GO

/* ============================================================================
   2. LA HISTORIA DE RECEPCIONES

   Lo mismo que devolvia ComprasProyectadasDatos::historiaRecepciones(), pero
   para TODOS los anios completos que el SP cubre (hoy, los ultimos diez) y no
   solo los de la cuota. Asi cambiar compras_proy_anios_cuota no obliga a
   volver a correr nada: PHP filtra los anios que usa.

   IMPORTE_USD es el FOB PRORRATEADO por cantidad sobre el total de la orden,
   con cuatro decimales para que la suma por anio no pierda centavos contra el
   calculo en vivo.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_RECEP_HIST', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_RECEP_HIST (
        ANIO          SMALLINT      NOT NULL,
        MES           TINYINT       NOT NULL,
        UNIDADES      DECIMAL(18,4) NOT NULL,
        IMPORTE_USD   DECIMAL(18,4) NOT NULL,
        FECHA_CALCULO DATETIME      NOT NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_RECEP_HIST PRIMARY KEY CLUSTERED (ANIO, MES),
        CONSTRAINT CK_RO_T_CF_RECEP_HIST_MES CHECK (MES BETWEEN 1 AND 12)
    );

    PRINT 'RO_T_CASHFLOW_COMEX_RECEP_HIST creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMEX_RECEP_HIST ya existia.';
GO

/* ============================================================================
   3. EL PRESUPUESTO OFICIAL, UNA FILA POR PAIS Y TEMPORADA

   Lo mismo que devolvia ComprasProyectadasDatos::versionesOficiales(): el
   tramo objetivo de la version oficial vigente de cada temporada, ya
   valorizado. Viene de RO_V_COMPRA_PROYECTADA_VIGENTE en POWER_BI_CONTROL por
   el linked server [XL-APPS].

   FECHA_CALCULO_VERSION es la fecha de calculo DE LA VERSION -la que decide
   que contenedores ya estan adentro del presupuesto- y FECHA_CALCULO es
   cuando corrio el SP. Son dos cosas distintas y por eso son dos columnas.

   TODOS LOS PAISES, aunque el cashflow hoy lee solo argentina: el filtro es
   de quien lee, y guardar uno solo haria que Uruguay necesite otro SP.

   El desglose por rubro (detalleVersion) NO se materializa: se pide solo al
   abrir el detalle de una version, y ahi un cuarto de segundo no molesta.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN (
        PAIS                  VARCHAR(50)   NOT NULL,
        TEMPORADA             VARCHAR(30)   NOT NULL,
        ID_VERSION            INT           NOT NULL,
        NOMBRE                VARCHAR(255)  NULL,
        SOLAPA                VARCHAR(255)  NULL,
        FECHA_CALCULO_VERSION DATE          NULL,
        TRAMOS_ESTADO         VARCHAR(100)  NULL,
        TEMPORADA_DESDE       DATE          NULL,
        TEMPORADA_HASTA       DATE          NULL,
        FILAS                 INT           NOT NULL,
        FILAS_SIN_COSTO       INT           NOT NULL,
        UNIDADES              DECIMAL(18,2) NOT NULL,
        UNIDADES_SIN_COSTO    DECIMAL(18,2) NOT NULL,
        FOB_USD               DECIMAL(18,4) NOT NULL,
        NAC_SEGUN_INC_FOB     DECIMAL(18,4) NOT NULL,
        DEFICIT_COBERTURA     DECIMAL(18,2) NOT NULL,
        FECHA_CALCULO         DATETIME      NOT NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN PRIMARY KEY CLUSTERED (PAIS, TEMPORADA)
    );

    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN ya existia.';
GO

/* ============================================================================
   4. EL CONTRASTE DE LA VISTA CONTRA SUS TABLAS, UNA FILA POR PAIS

   Lo que devolvia ComprasProyectadasDatos::contrasteVista(): cuantas filas
   del tramo objetivo deja afuera la vista respecto de sus tablas de origen.
   Hoy son 34 filas y U$S 2,2 millones de FOB -los rubros de cuero-, y el
   aviso existe para que ese filtro no sea invisible desde el cashflow.

   VA EN SU PROPIA TABLA y no repetido en cada fila del resumen: es un dato
   por pais y no por temporada, y un pais sin ninguna version oficial no
   tendria fila en el resumen donde colgarlo.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE (
        PAIS          VARCHAR(50)   NOT NULL,
        FILAS_VISTA   INT           NOT NULL,
        FOB_VISTA     DECIMAL(18,4) NOT NULL,
        FILAS_TABLAS  INT           NOT NULL,
        FOB_TABLAS    DECIMAL(18,4) NOT NULL,
        FECHA_CALCULO DATETIME      NOT NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE PRIMARY KEY CLUSTERED (PAIS)
    );

    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE ya existia.';
GO

/* ----------------------------------------------------------------------------
   Estado final.
   ---------------------------------------------------------------------------- */
SELECT t.nombre,
       CASE WHEN OBJECT_ID('dbo.' + t.nombre, 'U') IS NULL THEN 'FALTA' ELSE 'OK' END AS estado
FROM (VALUES ('RO_T_CASHFLOW_JOB_LOG'), ('RO_T_CASHFLOW_COMEX_RECEP_HIST'),
             ('RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN'), ('RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE')) t (nombre);
GO

PRINT 'Listo. Siguiente: sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql y sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql.';
GO
