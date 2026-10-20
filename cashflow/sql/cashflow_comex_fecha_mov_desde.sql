/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: CONTENEDORES DESDE UNA FECHA DE MOVIMIENTO
   Siembra el parametro comex_fecha_mov_desde
   ----------------------------------------------------------------------------
   Base    : central
   Tabla   : RO_T_CASHFLOW_PARAMETROS (no crea ninguna)
   Orden   : en cualquier momento. Es el 41 de la instalacion completa
             (README-cashflow.md). Con sql/migracion_parametros_modulo.sql
             corrido, el parametro aparece en Parametros > Compras Exterior;
             sin la columna MODULO se crea igual y la pestana lo muestra con
             los de Ventas.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Proveedores Exterior, Crono Nacionalizacion y el descuento de lo ya cargado
   de Compras Proyectadas traen solo los contenedores cuyo FECHA_MOV -el de la
   OC PRINCIPAL, COALESCE(ID_PADRE, ID), que es el criterio de Comercio
   Exterior- es igual o posterior a esta fecha. Por defecto 01/01/2025.

   El padron arrastraba contenedores de 2023 y 2024 que nadie va a pagar ni
   nacionalizar desde el cashflow: al 20/10/2026 eran 149 de las 348 filas de
   Proveedores Exterior, y ninguno sumaba en el eje. La regla y el porque estan
   en el encabezado de Comex::sqlDesdeFechaMov() y en README-comex.md.

   ----------------------------------------------------------------------------
   SIN ESTE SCRIPT EL CORTE SE APLICA IGUAL, con 01/01/2025: el codigo tiene el
   mismo valor inicial escrito (Comex::FECHA_MOV_DESDE_INICIAL). Lo unico que
   falta es poder cambiarlo, y Parametros > Compras Exterior dice que script
   correr. Las pestanas no avisan nada.

   NO PISA UN VALOR YA CARGADO: si el parametro existe, solo se lo reapunta al
   modulo y al grupo donde se dibuja, y se le corrige el TIPO_DATO. Es
   reejecutable.

   TIPO_DATO 'DATE' es nuevo en la tabla (hasta aca INT, DECIMAL, TEXT y CSV).
   Nadie lo lee para decidir nada: el formulario de Compras Exterior dibuja cada
   campo segun su clave (Js/Parametros-Compras_proy.js). Queda para que quien
   mire la tabla sepa que es una fecha 'aaaa-mm-dd'.
   ============================================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = 'comex_fecha_mov_desde')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, FECHA_UPDATE)
        VALUES ('comex_fecha_mov_desde', '2025-01-01', 'DATE',
                'Contenedores desde (fecha de movimiento): Proveedores Exterior, Crono Nacionalizacion y el descuento de Compras Proyectadas traen solo los de FECHA_MOV igual o posterior',
                'GENERAL', GETDATE());
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO, FECHA_UPDATE)
            VALUES (''comex_fecha_mov_desde'', ''2025-01-01'', ''DATE'',
                    ''Contenedores desde (fecha de movimiento): Proveedores Exterior, Crono Nacionalizacion y el descuento de Compras Proyectadas traen solo los de FECHA_MOV igual o posterior'',
                    ''GENERAL'', ''COMPRAS_PROY'', GETDATE());';

    PRINT 'Parametro comex_fecha_mov_desde sembrado en 2025-01-01.';
END
ELSE
BEGIN
    PRINT 'El parametro comex_fecha_mov_desde ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   Donde se dibuja: modulo COMPRAS_PROY, grupo GENERAL (la seccion 'generales'
   de Parametros > Compras Exterior pide ese grupo). NO toca el VALOR.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
SET GRUPO = 'GENERAL', TIPO_DATO = 'DATE'
WHERE CLAVE = 'comex_fecha_mov_desde'
  AND (ISNULL(GRUPO, '') <> 'GENERAL' OR ISNULL(TIPO_DATO, '') <> 'DATE');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NOT NULL
    EXEC sp_executesql N'
        UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
        SET MODULO = ''COMPRAS_PROY''
        WHERE CLAVE = ''comex_fecha_mov_desde'' AND ISNULL(MODULO, '''') <> ''COMPRAS_PROY'';';
GO

/* ----------------------------------------------------------------------------
   Control: tiene que ser una fecha 'aaaa-mm-dd' y no futura.

   Se pudo haber editado por fuera de la pantalla. Con un valor invalido el
   codigo usa 01/01/2025 (Comex::fechaMovDesde()); esto lo avisa al correr.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE CLAVE = 'comex_fecha_mov_desde'
      AND (VALOR NOT LIKE '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
           OR TRY_CONVERT(DATE, VALOR, 23) IS NULL
           OR TRY_CONVERT(DATE, VALOR, 23) > CAST(GETDATE() AS DATE))
)
BEGIN
    RAISERROR('ATENCION: comex_fecha_mov_desde no es una fecha aaaa-mm-dd valida y no futura. Corregila en Parametros -> Compras Exterior: mientras tanto se usa 2025-01-01.', 16, 1);
END
GO

PRINT 'Contenedores desde (fecha de movimiento): listo.';
GO
