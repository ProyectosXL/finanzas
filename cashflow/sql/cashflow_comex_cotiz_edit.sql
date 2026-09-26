/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES EXTERIOR: COTIZACION EDITABLE POR CONTENEDOR
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : PRIMERO de los dos scripts de esta entrega. No depende de ninguno
             otro, pero la tabla RO_T_CASHFLOW_COMEX_CRONO_NAC tiene que existir
             -es la que ya guarda FECHA_PAGO_EDIT y FECHA_NAC_EDIT-.
             Despues: sql/cashflow_prov_locales_opciones.sql
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Los pagos a proveedores del exterior se valuaban con UN parametro global,
   'comex_tipo_cambio_usd': un unico numero cargado a mano con el que se
   convertian por igual el contenedor que se paga el mes que viene y el que se
   paga dentro de once meses.

   Ahora se valuan con la CURVA DE DOLAR FUTURO ROFEX
   ([XL-APPS].sistemas.dbo.FP_DOLAR_FUTURO_ROFEX), segun el mes y el anio de la
   fecha estimada de pago de cada contenedor. El dolar futuro es el UNICO
   criterio: 'comex_tipo_cambio_usd' quedo en Parametros::RETIRADOS, la pantalla
   ya no lo muestra y su fila sigue en la base por si hay que reconstruir con
   que numero se proyecto en su momento.

   Ver el encabezado de cashflow/Class/DolarFuturo.php.

   ----------------------------------------------------------------------------
   POR QUE HACE FALTA UNA COLUMNA

   Porque la curva no siempre tiene la ultima palabra. Comercio Exterior puede
   tener cerrada una operacion a un tipo de cambio que el mercado no refleja
   -un pago ya calzado, un acuerdo con el proveedor-, y para esa fila el numero
   correcto lo sabe una persona y no el ROFEX.

   COTIZ_USD_EDIT es ese override, POR CONTENEDOR. Es nullable a proposito: NULL
   significa "manda la curva", que es el caso normal, y no "cotizacion cero".
   Vaciar el campo desde la pantalla vuelve a poner NULL y la fila vuelve a la
   curva.

   NO SE TOCA FP_DOLAR_FUTURO_ROFEX. Es la tabla maestra del mercado y para este
   modulo es de SOLO LECTURA: la mantiene el proceso que baja la curva. Un
   override que escribiera ahi cambiaria la valuacion de todos los contenedores
   de ese mes en lugar de la de uno, y ademas lo pisaria la proxima baja.

   ----------------------------------------------------------------------------
   EL OVERRIDE SE DESCARTA SI CAMBIA EL MES DE PAGO

   Lo hace el codigo -Comex::updateFechaPago()-, en la MISMA transaccion que el
   update de la fecha, y la respuesta se lo avisa al usuario.

   El motivo es que un override es una afirmacion sobre un mes: "este pago de
   noviembre se valua a tanto". Si el pago se corre a febrero, esa afirmacion ya
   no dice nada sobre la fila, y dejarla seria valuar febrero con un numero que
   alguien penso para noviembre, sin que nada en la pantalla lo indique.

   ----------------------------------------------------------------------------
   AUDITORIA

   No se agrega columna de usuario: todavia no hay login en el modulo y se
   grabaria NULL en todos lados. La tabla ya tiene FECHA_UPDATE y es la que se
   usa, igual que para las dos fechas editables que esta tabla ya guarda.

   DECIMAL(12,4) es la misma precision que FP_DOLAR_FUTURO_ROFEX.cotizacion: una
   precision menor redondearia el override y lo dejaria distinto del numero que
   la persona tipeo, que es exactamente lo que no puede pasar con un valor
   cargado a mano.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_COMEX_CRONO_NAC, que es la que guarda las fechas editadas de Comercio Exterior. Sin ella no hay donde guardar el override de cotizacion.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La columna.

   SIN DEFAULT, y es deliberado: el default seria un valor, y un valor significa
   "esta fila tiene override". Lo que hay que poder decir es "esta fila NO
   tiene", y eso es NULL.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC', 'COTIZ_USD_EDIT') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC
        ADD COTIZ_USD_EDIT DECIMAL(12,4) NULL;

    PRINT 'Columna COTIZ_USD_EDIT agregada.';
END
ELSE
BEGIN
    PRINT 'La columna COTIZ_USD_EDIT ya existia: no se hizo nada.';
END
GO

/* ----------------------------------------------------------------------------
   2. Control: una cotizacion tiene que ser positiva.

   Un cero o un negativo no son "sin override": son un override que valuaria el
   contenedor en cero o en negativo. El codigo ya lo rechaza
   -DolarFuturo::validarCotizacion()- y esto lo fija tambien en la base, para
   que una correccion a mano sobre la tabla no pueda dejar una fila que la
   pantalla no sabe explicar.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC', 'COTIZ_USD_EDIT') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
        WHERE name = 'CK_RO_T_CF_COMEX_CRONO_NAC_COTIZ'
   )
BEGIN
    /* Las filas que ya estuvieran mal se limpian antes: con una sola fila
       invalida el ALTER falla entero y el script no termina de correr. NULL es
       "manda la curva", que es el estado correcto para una fila cuyo override
       no se puede interpretar. */
    UPDATE dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC
    SET COTIZ_USD_EDIT = NULL
    WHERE COTIZ_USD_EDIT IS NOT NULL AND COTIZ_USD_EDIT <= 0;

    IF @@ROWCOUNT > 0
        PRINT 'ATENCION: se limpiaron overrides de cotizacion no positivos.';

    ALTER TABLE dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC
        ADD CONSTRAINT CK_RO_T_CF_COMEX_CRONO_NAC_COTIZ
            CHECK (COTIZ_USD_EDIT IS NULL OR COTIZ_USD_EDIT > 0);

    PRINT 'Control de cotizacion positiva agregado.';
END
GO

/* ----------------------------------------------------------------------------
   3. Control final: como quedo, y si la curva se alcanza desde esta base.

   La segunda consulta es la que importa en una instalacion nueva: si devuelve
   error, el problema es el servidor vinculado XL-APPS y no esta aplicacion, y
   la pestana lo va a decir con el mismo mensaje.
   ---------------------------------------------------------------------------- */
SELECT COUNT(*) AS FILAS,
       SUM(CASE WHEN COTIZ_USD_EDIT IS NOT NULL THEN 1 ELSE 0 END) AS CON_OVERRIDE
FROM dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC;
GO

BEGIN TRY
    SELECT COUNT(*) AS MESES_EN_LA_CURVA,
           MIN(anio * 100 + mes) AS DESDE,
           MAX(anio * 100 + mes) AS HASTA
    FROM [XL-APPS].sistemas.dbo.FP_DOLAR_FUTURO_ROFEX;
END TRY
BEGIN CATCH
    PRINT 'ATENCION: no se pudo leer [XL-APPS].sistemas.dbo.FP_DOLAR_FUTURO_ROFEX desde esta base.';
    PRINT 'Revisa el servidor vinculado XL-APPS. La pestana Proveedores Exterior va a avisar y mostrar los pagos sin valuar.';
END CATCH
GO

PRINT 'Proveedores Exterior listo para valuar con dolar futuro ROFEX.';
GO
