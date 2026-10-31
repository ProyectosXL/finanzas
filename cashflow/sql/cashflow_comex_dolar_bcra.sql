/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: DOLAR BCRA PARA LOS PAGOS CERCANOS
   Siembra comex_dias_bcra y comex_bcra_antiguedad_max
   ----------------------------------------------------------------------------
   Base    : central
   Tabla   : RO_T_CASHFLOW_PARAMETROS (no crea ninguna)
   Orden   : en cualquier momento. Es el 45 de la instalacion completa
             (README-cashflow.md). Con sql/migracion_parametros_modulo.sql
             corrido, los dos aparecen en Parametros > Compras Exterior.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Proveedores Exterior, Crono Nacionalizacion y Compras Proyectadas pasan a
   pesos un pago en dolares que sale dentro de los proximos N dias -o que ya
   vencio- con el dolar oficial BCRA, punta VENDEDORA, y no con la curva ROFEX:
   un pago inminente se liquida al tipo de cambio de hoy. Los posteriores
   siguen con la curva del mes de pago. La regla y el porque estan en el
   encabezado de Class/DolarFuturo.php y en README-comex.md.

     comex_dias_bcra            N: dias corridos desde hoy que van a BCRA.
                                7 por defecto. Con 0, todo va con la curva.
     comex_bcra_antiguedad_max  M: dias corridos a partir de los cuales la
                                ultima cotizacion BCRA se avisa como vieja.
                                3 por defecto. Se usa igual.

   ----------------------------------------------------------------------------
   SIN ESTE SCRIPT LA REGLA SE APLICA IGUAL, con 7 y 3: el codigo tiene los
   mismos valores escritos (DolarFuturo::DIAS_BCRA_INICIAL y
   ANTIGUEDAD_BCRA_INICIAL). Lo unico que falta es poder cambiarlos, y
   Parametros > Compras Exterior dice que script correr.

   NO PISA UN VALOR YA CARGADO: si el parametro existe, solo se lo reapunta al
   modulo y al grupo donde se dibuja. Es reejecutable.

   La fecha y el usuario de la ultima modificacion los completan los DEFAULT
   de sql/cashflow_auditoria_usuario.sql al sembrar, y saveParametro() con el
   usuario de la sesion en cada edicion: sin usuario, la escritura se rechaza.
   ============================================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = 'comex_dias_bcra')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('comex_dias_bcra', '7', 'INT',
                'Dias con dolar BCRA: los pagos en dolares de Comercio Exterior que salen dentro de estos dias -o ya vencidos- se pasan a pesos con el dolar oficial BCRA vendedor; los posteriores, con la curva ROFEX',
                'GENERAL');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
            VALUES (''comex_dias_bcra'', ''7'', ''INT'',
                    ''Dias con dolar BCRA: los pagos en dolares de Comercio Exterior que salen dentro de estos dias -o ya vencidos- se pasan a pesos con el dolar oficial BCRA vendedor; los posteriores, con la curva ROFEX'',
                    ''GENERAL'', ''COMPRAS_PROY'');';

    PRINT 'Parametro comex_dias_bcra sembrado en 7.';
END
ELSE
BEGIN
    PRINT 'El parametro comex_dias_bcra ya existe: no se toca su valor.';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = 'comex_bcra_antiguedad_max')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('comex_bcra_antiguedad_max', '3', 'INT',
                'Antiguedad maxima del dolar BCRA: si la ultima cotizacion publicada tiene mas de estos dias corridos, se usa igual y se avisa',
                'GENERAL');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
            VALUES (''comex_bcra_antiguedad_max'', ''3'', ''INT'',
                    ''Antiguedad maxima del dolar BCRA: si la ultima cotizacion publicada tiene mas de estos dias corridos, se usa igual y se avisa'',
                    ''GENERAL'', ''COMPRAS_PROY'');';

    PRINT 'Parametro comex_bcra_antiguedad_max sembrado en 3.';
END
ELSE
BEGIN
    PRINT 'El parametro comex_bcra_antiguedad_max ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   Donde se dibujan: modulo COMPRAS_PROY, grupo GENERAL (la seccion
   'generales' de Parametros > Compras Exterior pide ese grupo). NO toca el
   VALOR.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
SET GRUPO = 'GENERAL', TIPO_DATO = 'INT'
WHERE CLAVE IN ('comex_dias_bcra', 'comex_bcra_antiguedad_max')
  AND (ISNULL(GRUPO, '') <> 'GENERAL' OR ISNULL(TIPO_DATO, '') <> 'INT');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NOT NULL
    EXEC sp_executesql N'
        UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
        SET MODULO = ''COMPRAS_PROY''
        WHERE CLAVE IN (''comex_dias_bcra'', ''comex_bcra_antiguedad_max'')
          AND ISNULL(MODULO, '''') <> ''COMPRAS_PROY'';';
GO

/* ----------------------------------------------------------------------------
   Control: enteros de 0 a 60 (N) y de 0 a 30 (M).

   Se pudieron haber editado por fuera de la pantalla. Con un valor invalido
   el codigo usa el inicial (DolarFuturo::diasBcra() y antiguedadBcra()); esto
   lo avisa al correr.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE (CLAVE = 'comex_dias_bcra'
           AND (VALOR LIKE '%[^0-9]%' OR TRY_CAST(VALOR AS INT) IS NULL
                OR TRY_CAST(VALOR AS INT) NOT BETWEEN 0 AND 60))
       OR (CLAVE = 'comex_bcra_antiguedad_max'
           AND (VALOR LIKE '%[^0-9]%' OR TRY_CAST(VALOR AS INT) IS NULL
                OR TRY_CAST(VALOR AS INT) NOT BETWEEN 0 AND 30))
)
BEGIN
    RAISERROR('ATENCION: comex_dias_bcra tiene que ser un entero de 0 a 60 y comex_bcra_antiguedad_max uno de 0 a 30. Corregilos en Parametros -> Compras Exterior: mientras tanto se usan 7 y 3.', 16, 1);
END
GO

PRINT 'Dolar BCRA para los pagos cercanos: listo.';
GO
