/* ============================================================================
   INFORME ECONOMICO POR CANAL - ESTRUCTURA DE FILAS
   ----------------------------------------------------------------------------
   Base   : central. Se corre TAMBIEN en la base de Uruguay (TASKY_SA), para que
            quede lista el dia que el informe se habilite alla (hoy la pantalla
            dice "no disponible para Uruguay" y no consulta nada).
   Orden  : 1 de 4. No depende de ningun otro.
   ----------------------------------------------------------------------------
   QUE HACE

   Crea RO_T_IE_ESTRUCTURA_FILA y siembra la cascada del Excel "3. IE CANALES",
   en el mismo orden.

   LA CONFIGURACION DECIDE ORDEN, ETIQUETA Y VISIBILIDAD; NUNCA LA CUENTA.
   Las filas CALCULO y RATIO nombran una formula por CLAVE, y la formula vive en
   informe_economico/Class/Formulas.php, con pruebas. Una clave que el codigo no
   conoce no se muestra y la pantalla lo avisa.

   Los cinco tipos:
     TITULO             un rotulo ("1. VENTAS")
     RUBRO              un codigo puntual; CLAVE = COD_RUBRO, con punto final
                        como en la tabla ('1.5.'). Se usa para 1.x y 2.
     RUBROS_DE_SECCION  todos los rubros de la CAT_RUBRO_CONTABLE que dice
                        SECCION, ordenados por codigo. Un rubro nuevo entra solo
                        en cuanto tiene categoria.
     CALCULO / RATIO    una formula del catalogo; RATIO da un %.

   NO HAY FILAS DE AJUSTE MANUAL: las correcciones se hacen editando importes
   del resumen, con historial (sql/ie_resumen_edicion.sql).

   ----------------------------------------------------------------------------
   REEJECUTABLE Y SIN PISAR NADA

   La tabla se crea si no existe. La semilla inserta SOLO las claves que faltan:
   una fila que alguien reordeno, renombro u oculto desde Parametros no se
   toca. No se borra nada; una fila que ya no se quiere se da de baja con
   ACTIVO = 0.

   SI NO SE CORRE: el informe no se puede armar y la pantalla lo avisa
   nombrando este archivo.
   ========================================================================== */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_IE_ESTRUCTURA_FILA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_ESTRUCTURA_FILA (
        ID           INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_IE_ESTRUCTURA_FILA PRIMARY KEY,
        CLAVE        VARCHAR(40)   NOT NULL CONSTRAINT UQ_RO_T_IE_ESTRUCTURA_FILA_CLAVE UNIQUE,
        TIPO         VARCHAR(20)   NOT NULL CONSTRAINT CK_RO_T_IE_ESTRUCTURA_FILA_TIPO
                                   CHECK (TIPO IN ('TITULO', 'RUBRO', 'RUBROS_DE_SECCION', 'CALCULO', 'RATIO')),
        -- La CAT_RUBRO_CONTABLE que expande una fila RUBROS_DE_SECCION
        SECCION      NVARCHAR(100) NULL,
        ORDEN        INT           NOT NULL,
        ETIQUETA     NVARCHAR(150) NULL,
        VISIBLE      BIT           NOT NULL CONSTRAINT DF_RO_T_IE_ESTRUCTURA_FILA_VISIBLE DEFAULT (1),
        ACTIVO       BIT           NOT NULL CONSTRAINT DF_RO_T_IE_ESTRUCTURA_FILA_ACTIVO DEFAULT (1),
        USUARIO_ALTA VARCHAR(100)  NULL,
        FECHA_ALTA   DATETIME      NOT NULL CONSTRAINT DF_RO_T_IE_ESTRUCTURA_FILA_FALTA DEFAULT (GETDATE()),
        USUARIO_MOD  VARCHAR(100)  NULL,
        FECHA_MOD    DATETIME      NULL
    );

    PRINT 'Creada dbo.RO_T_IE_ESTRUCTURA_FILA.';
END
ELSE
    PRINT 'dbo.RO_T_IE_ESTRUCTURA_FILA ya existe: no se toca.';
GO

/* ---- Semilla: la cascada del Excel. Solo inserta las claves que faltan. ---- */
DECLARE @SEMILLA TABLE (CLAVE VARCHAR(40), TIPO VARCHAR(20), SECCION NVARCHAR(100), ORDEN INT, ETIQUETA NVARCHAR(150));

INSERT INTO @SEMILLA (CLAVE, TIPO, SECCION, ORDEN, ETIQUETA) VALUES
-- 1. VENTAS
('TIT_VENTAS',              'TITULO',  NULL, 10,  N'1. VENTAS'),
('1.1.',                    'RUBRO',   NULL, 20,  NULL),
('1.2.',                    'RUBRO',   NULL, 30,  NULL),
('V_1_3',                   'CALCULO', NULL, 40,  N'1.3 Ventas Minoristas con IVA'),
('V_1_4',                   'CALCULO', NULL, 50,  N'1.4 IVA Ventas'),
('1.5.',                    'RUBRO',   NULL, 60,  NULL),
('1.6.',                    'RUBRO',   NULL, 70,  NULL),
('1.7.',                    'RUBRO',   NULL, 80,  NULL),
('1.8.',                    'RUBRO',   NULL, 90,  NULL),
('V_1_9',                   'CALCULO', NULL, 100, N'1.9 TOTAL VENTAS SIN IVA'),
('PART_TOTAL',              'RATIO',   NULL, 110, N'Participación % Total'),
('PART_LOCALES',            'RATIO',   NULL, 120, N'Participación % Locales'),
-- 2. COSTO
('TIT_COSTO',               'TITULO',  NULL, 200, N'2. COSTO'),
('2.',                      'RUBRO',   NULL, 210, NULL),
('REL_COSTO_VENTAS',        'RATIO',   NULL, 220, N'Relación costo sobre ventas'),
('RESULTADO_BRUTO',         'CALCULO', NULL, 230, N'3. RESULTADO BRUTO'),
('TIT_MARKUP',              'TITULO',  NULL, 240, N'MARK UP'),
('REL_COSTO_VENTA_CON_IVA', 'RATIO',   NULL, 250, N'Relación costo / venta con IVA'),
('MARKUP_CON_IVA',          'RATIO',   NULL, 260, N'Mark up con IVA'),
('REL_COSTO_VENTA_SIN_IVA', 'RATIO',   NULL, 270, N'Relación costo / venta sin IVA'),
('MARKUP_SIN_IVA',          'RATIO',   NULL, 280, N'Mark up sin IVA'),
-- 4. COMERCIALIZACION
('TIT_COMERCIALIZACION',    'TITULO',  NULL, 400, N'4. GASTOS DE COMERCIALIZACIÓN'),
('SEC_COMERCIALIZACION',    'RUBROS_DE_SECCION', N'Gastos de Comercialización', 410, NULL),
('TOTAL_COMERCIALIZACION',  'CALCULO', NULL, 420, N'TOTAL GASTOS COMERCIALIZACIÓN'),
('REL_PROMOCIONES',         'RATIO',   NULL, 430, N'Relación % promociones tarjetas sobre ventas sin IVA'),
('REL_COSTO_FINANCIERO',    'RATIO',   NULL, 440, N'Relación costo financiero sobre venta en TJ'),
('REL_ARANCEL',             'RATIO',   NULL, 450, N'Relación arancel sobre venta en TJ'),
('REL_COMERCIALIZACION',    'RATIO',   NULL, 460, N'Relación gastos de comercialización sobre ventas'),
('RESULTADO_COMERCIAL',     'RATIO',   NULL, 470, N'RESULTADO COMERCIAL'),
-- 5. OPERATIVOS
('TIT_OPERATIVOS',          'TITULO',  NULL, 500, N'5. GASTOS OPERATIVOS'),
('TIT_PERSONAL',            'TITULO',  NULL, 510, N'5.1 Gastos de Personal'),
('SEC_PERSONAL',            'RUBROS_DE_SECCION', N'Gastos de Personal', 520, NULL),
('TOTAL_PERSONAL',          'CALCULO', NULL, 530, N'TOTAL GASTOS DE PERSONAL'),
('REL_PERSONAL',            'RATIO',   NULL, 540, N'Relación gastos de personal sobre ventas'),
('TIT_OCUPACION',           'TITULO',  NULL, 550, N'5.2 Gastos de Ocupación'),
('SEC_OCUPACION',           'RUBROS_DE_SECCION', N'Gastos de Ocupación', 560, NULL),
('TOTAL_OCUPACION',         'CALCULO', NULL, 570, N'TOTAL GASTOS DE OCUPACIÓN'),
('REL_OCUPACION',           'RATIO',   NULL, 580, N'Relación gastos de ocupación sobre ventas'),
('TIT_OTROS_OPERATIVOS',    'TITULO',  NULL, 590, N'5.3 Otros Gastos Operativos'),
('SEC_OTROS_OPERATIVOS',    'RUBROS_DE_SECCION', N'Otros Gastos Operativos', 600, NULL),
('TOTAL_OTROS_OPERATIVOS',  'CALCULO', NULL, 610, N'TOTAL OTROS GASTOS OPERATIVOS'),
('REL_OTROS_OPERATIVOS',    'RATIO',   NULL, 620, N'Relación otros gastos operativos sobre ventas'),
('TOTAL_OPERATIVO',         'CALCULO', NULL, 630, N'TOTAL GASTOS OPERATIVO'),
('RESULTADO_OPERATIVO',     'CALCULO', NULL, 640, N'RESULTADO OPERATIVO'),
('REL_RESULTADO_OPERATIVO', 'RATIO',   NULL, 650, N'% Resultado operativo sobre ventas'),
-- 6. BIENES DE USO
('TIT_BIENES_USO',          'TITULO',  NULL, 700, N'6. BIENES DE USO'),
('SEC_BIENES_USO',          'RUBROS_DE_SECCION', N'Bienes de Uso', 710, NULL),
('REL_BIENES_USO',          'RATIO',   NULL, 720, N'% Bienes de uso sobre ventas'),
('CONTRIBUCION_MARGINAL',   'CALCULO', NULL, 730, N'CONTRIBUCIÓN MARGINAL NETA'),
-- 7. ESTRUCTURA
('TIT_ESTRUCTURA',          'TITULO',  NULL, 800, N'7. GASTOS DE ESTRUCTURA'),
('SEC_ESTRUCTURA',          'RUBROS_DE_SECCION', N'Gastos de Estructura', 810, NULL),
('TOTAL_ESTRUCTURA',        'CALCULO', NULL, 820, N'TOTAL GASTOS DE ESTRUCTURA'),
('REL_ESTRUCTURA',          'RATIO',   NULL, 830, N'Relación gastos de estructura sobre ventas'),
('RESULTADO_EXPLOTACION',   'CALCULO', NULL, 840, N'RESULTADO EXPLOTACIÓN'),
('RENTABILIDAD_TOTAL',      'RATIO',   NULL, 850, N'RENTABILIDAD TOTAL'),
('MARGEN_BRUTO',            'RATIO',   NULL, 860, N'MARGEN BRUTO'),
('SUMA_COSTOS',             'RATIO',   NULL, 870, N'SUMA % COSTOS (EXCLUYE CMV)');

INSERT INTO dbo.RO_T_IE_ESTRUCTURA_FILA (CLAVE, TIPO, SECCION, ORDEN, ETIQUETA, USUARIO_ALTA)
SELECT S.CLAVE, S.TIPO, S.SECCION, S.ORDEN, S.ETIQUETA, 'sql/ie_estructura.sql'
FROM @SEMILLA S
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_IE_ESTRUCTURA_FILA F WHERE F.CLAVE = S.CLAVE);

PRINT CONCAT('Semilla: ', @@ROWCOUNT, ' filas nuevas (las existentes no se tocan).');
GO

/* ---- Control: cada seccion que se expande tiene que existir en el maestro ---- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_IE_ESTRUCTURA_FILA F
    WHERE F.TIPO = 'RUBROS_DE_SECCION' AND F.ACTIVO = 1
      AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_RUBROS_CONTABLES R WHERE R.CAT_RUBRO_CONTABLE = F.SECCION)
)
    PRINT 'ATENCION: hay filas RUBROS_DE_SECCION cuya SECCION no esta en RO_T_RUBROS_CONTABLES.CAT_RUBRO_CONTABLE. La pantalla lo avisa y no expande nada.';
GO

PRINT 'Estructura del Informe Economico lista.';
GO
