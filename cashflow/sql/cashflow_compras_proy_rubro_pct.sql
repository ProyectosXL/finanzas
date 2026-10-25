/* ============================================================================
   MODULO CASHFLOW - COMPRAS EXTERIOR: EL % IMPORTADO POR RUBRO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_comex_materializado.sql (que agrega la
            tabla por rubro y la columna del FOB sin ajustar) y ANTES de
            sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql, que lee esta tabla.
   ----------------------------------------------------------------------------
   QUE HACE

   1. Crea RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT: que parte de cada rubro del
      presupuesto se compra en el exterior.
   2. Siembra los 20 rubros de hoy: cuero, cosmetica, lentes y relojes en 0 %;
      el resto en 100 %.

   POR QUE EXISTE

   RO_V_COMPRA_PROYECTADA_VIGENTE tenia un filtro propio -rubro NOT LIKE
   '%cuero%'- que dejaba afuera los rubros de cuero sin que el cashflow lo
   supiera. Ese filtro se saco de la vista: hoy devuelve TODOS los rubros, y
   que parte de cada uno entra en la proyeccion lo decide el cashflow con
   esta tabla. El job (RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN) multiplica cada
   fila del presupuesto por su % ANTES de sumar por temporada:

       FOB del rubro x % importado / 100

   UN RUBRO QUE NO ESTA EN LA TABLA ENTRA AL 100 %, y el cashflow avisa
   "Rubro nuevo sin configurar". El job NO lo da de alta: sigue sin
   configurar hasta que alguien le asigne un % desde Parametros > Compras
   Exterior.

   SIN BAJAS FISICAS. Cambiar un % marca VIGENTE = 0 la fila anterior e
   inserta una nueva (ComprasProyectadasRubros::guardar()), igual que el
   ajuste manual por mes. El indice unico filtrado por VIGENTE = 1 prohibe dos
   vigentes del mismo rubro.

   EL RUBRO SE GUARDA NORMALIZADO: en mayusculas, sin espacios al principio ni
   al final y con los espacios repetidos colapsados a uno. El SP normaliza
   igual el rubro de la vista antes de cruzarlos, asi que "Cosmetica " y
   "COSMETICA" son el mismo rubro y el indice unico no deja cargar los dos.

   ES REEJECUTABLE: la tabla pregunta por OBJECT_ID, y la semilla solo carga
   los rubros que NO tienen un % vigente. Un % editado desde la pantalla no
   se pisa.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. LA TABLA
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT (
        ID            INT IDENTITY(1,1) NOT NULL,
        /* Normalizado: ver el encabezado. */
        RUBRO         VARCHAR(100)  NOT NULL,
        /* 0 = el rubro no se proyecta; 100 = entra completo. */
        PCT_IMPORTADO DECIMAL(5,2)  NOT NULL,
        VIGENTE       BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_CPRUB_VIGENTE DEFAULT (1),

        USUARIO_ALTA  VARCHAR(50)   NULL,
        FECHA_ALTA    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_CPRUB_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50)   NULL,
        FECHA_MODIF   DATETIME      NULL
            CONSTRAINT DF_RO_T_CF_CPRUB_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA  VARCHAR(50)   NULL,
        FECHA_BAJA    DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_CPRUB_PCT CHECK (PCT_IMPORTADO >= 0 AND PCT_IMPORTADO <= 100)
    );

    /* UN SOLO % VIGENTE POR RUBRO. Filtrado: un UNIQUE comun prohibiria
       tambien el historial, que es lo que la tabla existe para guardar. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_CPRUB_RUBRO_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT (RUBRO)
        INCLUDE (PCT_IMPORTADO)
        WHERE VIGENTE = 1;

    PRINT 'RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT ya existia.';
GO

/* ============================================================================
   2. LA SEMILLA

   SOLO LOS RUBROS SIN % VIGENTE: un valor ya editado no se pisa, y una
   segunda corrida no inserta nada.

   QUIEN LOS CARGO: 'SISTEMA:' + el nombre del script, como la semilla de las
   retenciones de Proveedores Locales. NULL significa en este modulo "fila
   historica, de antes de la auditoria", y estas no lo son.
   ============================================================================ */
DECLARE @Usuario VARCHAR(50) = 'SISTEMA:cashflow_compras_proy_rubro_pct.sql';

INSERT INTO dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT
    (RUBRO, PCT_IMPORTADO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
SELECT s.RUBRO, s.PCT, 1, @Usuario, @Usuario
FROM (VALUES
    ('ACCESORIOS DE CUERO',      0),
    ('BILLETERAS DE CUERO',      0),
    ('CARTERAS DE CUERO',        0),
    ('CINTOS DE CUERO',          0),
    ('COSMETICA',                0),
    ('LENTES',                   0),
    ('RELOJES',                  0),
    ('ACCESORIOS DE VINILICO', 100),
    ('BIJOU',                  100),
    ('BILLETERAS DE VINILICO', 100),
    ('CALZADOS',               100),
    ('CAMPERAS',               100),
    ('CARTERAS DE VINILICO',   100),
    ('CHALINAS',               100),
    ('EQUIPAJES',              100),
    ('HOME',                   100),
    ('INDUMENTARIA',           100),
    ('LLAVEROS',               100),
    ('PARAGUAS',               100),
    ('TOALLAS',                100)
) AS s (RUBRO, PCT)
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT p
                  WHERE p.RUBRO = s.RUBRO AND p.VIGENTE = 1);

PRINT CONCAT('Semilla: ', @@ROWCOUNT, ' rubro(s) cargado(s); los que ya tenian un % vigente no se tocaron.');
GO

/* ----------------------------------------------------------------------------
   Estado final: los vigentes, y si algun rubro quedo con mas de uno (no
   deberia: lo impide el indice).
   ---------------------------------------------------------------------------- */
SELECT RUBRO, PCT_IMPORTADO, USUARIO_ALTA, FECHA_ALTA
FROM dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT
WHERE VIGENTE = 1
ORDER BY PCT_IMPORTADO, RUBRO;
GO

PRINT 'Listo. Siguiente: sql/RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql, y correr el SP (o Actualizar ahora).';
GO
