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
   4. RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE la vista contra sus tablas: control
                                           de que nadie le puso un filtro
   5. RO_T_CASHFLOW_COMEX_PRESUP_RUBRO    el presupuesto por rubro, con el %
                                           importado que se le aplico
   Y agrega a RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN la columna FOB_PRESUPUESTO_USD,
   el FOB sin el % importado (seccion 3b). Las dos ultimas son de
   feature/proyeccion-pct-importado-rubro: en una base que ya tenia las
   demas, volver a correr este script agrega solo eso.

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
   3b. EL FOB DEL PRESUPUESTO SIN AJUSTAR

   FOB_USD es el FOB YA MULTIPLICADO por el % importado de cada rubro: es el
   que se proyecta. FOB_PRESUPUESTO_USD es el que dice la app de compras, sin
   ajustar, y existe solo para poder comparar las dos pantallas.

   NULL en las filas que grabo un SP anterior a esta columna: el cashflow las
   lee como "sin ajuste" (igual a FOB_USD) hasta la proxima corrida.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN', 'FOB_PRESUPUESTO_USD') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN
        ADD FOB_PRESUPUESTO_USD DECIMAL(18,4) NULL;

    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN: + FOB_PRESUPUESTO_USD.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN.FOB_PRESUPUESTO_USD ya existia.';
GO

/* ============================================================================
   4. EL CONTRASTE DE LA VISTA CONTRA SUS TABLAS, UNA FILA POR PAIS

   Lo que devuelve ComprasProyectadasDatos::contrasteVista(): cuantas filas
   del tramo objetivo deja afuera la vista respecto de sus tablas de origen.

   ES UN CONTROL DE SEGURIDAD. La vista tuvo un filtro propio -los rubros de
   cuero- que se saco: que parte de cada rubro se proyecta lo decide ahora el
   % importado del cashflow (RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT). Si la
   vista vuelve a devolver menos filas que sus tablas, alguien le puso un
   filtro en la base que el cashflow no ve, y la pantalla lo avisa.

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

/* ============================================================================
   5. EL PRESUPUESTO POR RUBRO, CON SU % IMPORTADO

   Una fila por pais, temporada y rubro: el FOB que dice el presupuesto, el %
   importado que el SP le aplico y el FOB que quedo para proyectar.

   SE MATERIALIZA PARA QUE TRES PANTALLAS DIGAN LO MISMO QUE EL JOB HIZO: el
   aviso de lo que queda fuera por el %, el de los rubros sin configurar, y
   el FOB por rubro de Parametros > Compras Exterior. Leido en vivo de la
   vista, cualquiera de los tres podria describir un presupuesto mas nuevo
   que el que se esta proyectando.

   RUBRO va normalizado, igual que en RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT.
   CONFIGURADO = 0 es un rubro que no tenia % y entro al 100 %.

   Auditoria: el SP llena alta y modificacion con quien lo pidio. Se
   reescribe entera en cada corrida, asi que no hay baja que registrar.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO (
        PAIS            VARCHAR(50)   NOT NULL,
        TEMPORADA       VARCHAR(30)   NOT NULL,
        RUBRO           VARCHAR(100)  NOT NULL,
        ID_VERSION      INT           NOT NULL,
        FILAS           INT           NOT NULL,
        FOB_PRESUPUESTO DECIMAL(18,4) NOT NULL,
        PCT_APLICADO    DECIMAL(5,2)  NOT NULL,
        CONFIGURADO     BIT           NOT NULL,
        FOB_PROYECTADO  DECIMAL(18,4) NOT NULL,
        FECHA_CALCULO   DATETIME      NOT NULL,

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NULL
            CONSTRAINT DF_RO_T_CF_PRESRUB_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NULL
            CONSTRAINT DF_RO_T_CF_PRESRUB_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_PRESUP_RUBRO PRIMARY KEY CLUSTERED (PAIS, TEMPORADA, RUBRO)
    );

    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RUBRO creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMEX_PRESUP_RUBRO ya existia.';
GO

/* ----------------------------------------------------------------------------
   Estado final.
   ---------------------------------------------------------------------------- */
SELECT t.nombre,
       CASE WHEN OBJECT_ID('dbo.' + t.nombre, 'U') IS NULL THEN 'FALTA' ELSE 'OK' END AS estado
FROM (VALUES ('RO_T_CASHFLOW_JOB_LOG'), ('RO_T_CASHFLOW_COMEX_RECEP_HIST'),
             ('RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN'), ('RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE'),
             ('RO_T_CASHFLOW_COMEX_PRESUP_RUBRO')) t (nombre);

SELECT CASE WHEN COL_LENGTH('dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN', 'FOB_PRESUPUESTO_USD') IS NULL
            THEN 'FALTA' ELSE 'OK' END AS resumen_fob_presupuesto;
GO

PRINT 'Listo. Siguiente: sql/cashflow_compras_proy_rubro_pct.sql, y despues sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql y sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql.';
GO
