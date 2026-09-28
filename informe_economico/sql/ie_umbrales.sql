/* ============================================================================
   INFORME ECONOMICO POR CANAL - UMBRALES DEL SEMAFORO Y PARAMETRO DE ALERTA
   ----------------------------------------------------------------------------
   Base   : central. Se corre TAMBIEN en la base de Uruguay (TASKY_SA).
   Orden  : 3 de 4. No depende de ningun otro.
   ----------------------------------------------------------------------------
   QUE HACE

   RO_T_IE_UMBRAL: una fila por indicador del Ranking de Locales, con su
   sentido y las bandas verde, amarillo, naranja y rojo. Los limites son
   FRACCIONES (0.15 = 15%). Una banda con DESDE y HASTA en NULL no esta
   definida; un valor que no cae en ninguna banda queda sin color.

   LOS BORDES VAN A LA BANDA PEOR (ver Class/Semaforo.php):
     MAYOR_MEJOR: cada banda es (DESDE, HASTA]
     MENOR_MEJOR: cada banda es [DESDE, HASTA)
   Asi "verde > 15%" y "verde < 15%" se cumplen al pie de la letra. Diferencia
   con el Excel: en los limites de ROJO el Excel pinta la banda de al lado (5%
   de rentabilidad es naranja; 20% de comercializacion es amarillo). Aca son
   rojo.

   RO_T_IE_PARAMETRO: parametros sueltos del modulo. Hoy uno solo:
     alerta_caida_rentabilidad_pp = 3   (puntos porcentuales, entero)
   Un local cuya rentabilidad cae mas de X puntos contra el anio anterior entra
   en las alertas del Dashboard.

   La semilla es la del Excel "5. Ranking Locales":
     Rentabilidad         verde > 15%, amarillo 12-15%, naranja 5-12%, rojo < 5%
     Costo de mercaderia  verde < 30% (sin otras bandas)
     Comercializacion     verde < 15%, amarillo 15-20%, rojo > 20%
     Personal             verde < 17%, amarillo 17-20%, rojo > 20%
     Ocupacion            verde < 15%, amarillo 15-18%, rojo > 18%

   Reejecutable: solo inserta lo que falta; un umbral editado desde Parametros
   no se pisa.

   SI NO SE CORRE: el ranking se ve sin colores, sin la alerta de "dos o mas
   indicadores en rojo" y sin la de caida de rentabilidad; la pantalla avisa.
   ========================================================================== */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_IE_UMBRAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_UMBRAL (
        ID             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_IE_UMBRAL PRIMARY KEY,
        INDICADOR      VARCHAR(30)   NOT NULL CONSTRAINT UQ_RO_T_IE_UMBRAL_INDICADOR UNIQUE,
        NOMBRE         NVARCHAR(100) NOT NULL,
        SENTIDO        VARCHAR(12)   NOT NULL CONSTRAINT CK_RO_T_IE_UMBRAL_SENTIDO
                                     CHECK (SENTIDO IN ('MAYOR_MEJOR', 'MENOR_MEJOR')),
        VERDE_DESDE    DECIMAL(9,4)  NULL,
        VERDE_HASTA    DECIMAL(9,4)  NULL,
        AMARILLO_DESDE DECIMAL(9,4)  NULL,
        AMARILLO_HASTA DECIMAL(9,4)  NULL,
        NARANJA_DESDE  DECIMAL(9,4)  NULL,
        NARANJA_HASTA  DECIMAL(9,4)  NULL,
        ROJO_DESDE     DECIMAL(9,4)  NULL,
        ROJO_HASTA     DECIMAL(9,4)  NULL,
        ORDEN          INT           NOT NULL,
        USUARIO_MOD    VARCHAR(100)  NULL,
        FECHA_MOD      DATETIME      NULL
    );

    PRINT 'Creada dbo.RO_T_IE_UMBRAL.';
END
ELSE
    PRINT 'dbo.RO_T_IE_UMBRAL ya existe: no se toca.';
GO

INSERT INTO dbo.RO_T_IE_UMBRAL (INDICADOR, NOMBRE, SENTIDO, VERDE_DESDE, VERDE_HASTA, AMARILLO_DESDE, AMARILLO_HASTA,
                                NARANJA_DESDE, NARANJA_HASTA, ROJO_DESDE, ROJO_HASTA, ORDEN, USUARIO_MOD)
SELECT S.INDICADOR, S.NOMBRE, S.SENTIDO, S.VD, S.VH, S.AD, S.AH, S.ND, S.NH, S.RD, S.RH, S.ORDEN, 'sql/ie_umbrales.sql'
FROM (VALUES
    ('RENTABILIDAD',      N'Rentabilidad total',   'MAYOR_MEJOR', 0.15, NULL, 0.12, 0.15, 0.05, 0.12, NULL, 0.05, 10),
    ('COSTO_MERCADERIA',  N'Costo de mercadería',  'MENOR_MEJOR', NULL, 0.30, NULL, NULL, NULL, NULL, NULL, NULL, 20),
    ('COMERCIALIZACION',  N'Gastos de comercialización', 'MENOR_MEJOR', NULL, 0.15, 0.15, 0.20, NULL, NULL, 0.20, NULL, 30),
    ('PERSONAL',          N'Gastos de personal',   'MENOR_MEJOR', NULL, 0.17, 0.17, 0.20, NULL, NULL, 0.20, NULL, 40),
    ('OCUPACION',         N'Gastos de ocupación',  'MENOR_MEJOR', NULL, 0.15, 0.15, 0.18, NULL, NULL, 0.18, NULL, 50)
) AS S (INDICADOR, NOMBRE, SENTIDO, VD, VH, AD, AH, ND, NH, RD, RH, ORDEN)
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_IE_UMBRAL U WHERE U.INDICADOR = S.INDICADOR);

PRINT CONCAT('Umbrales: ', @@ROWCOUNT, ' indicadores nuevos (los existentes no se tocan).');
GO

IF OBJECT_ID('dbo.RO_T_IE_PARAMETRO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_IE_PARAMETRO (
        CLAVE       VARCHAR(50)   NOT NULL CONSTRAINT PK_RO_T_IE_PARAMETRO PRIMARY KEY,
        VALOR       VARCHAR(100)  NOT NULL,
        DESCRIPCION NVARCHAR(300) NULL,
        USUARIO_MOD VARCHAR(100)  NULL,
        FECHA_MOD   DATETIME      NULL
    );

    PRINT 'Creada dbo.RO_T_IE_PARAMETRO.';
END
ELSE
    PRINT 'dbo.RO_T_IE_PARAMETRO ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_IE_PARAMETRO WHERE CLAVE = 'alerta_caida_rentabilidad_pp')
BEGIN
    INSERT INTO dbo.RO_T_IE_PARAMETRO (CLAVE, VALOR, DESCRIPCION, USUARIO_MOD, FECHA_MOD)
    VALUES ('alerta_caida_rentabilidad_pp', '3',
            N'Caída de rentabilidad, en puntos porcentuales enteros, a partir de la cual un local entra en las alertas del Dashboard.',
            'sql/ie_umbrales.sql', GETDATE());
    PRINT 'Sembrado alerta_caida_rentabilidad_pp = 3.';
END
GO

PRINT 'Umbrales del Informe Economico listos.';
GO
