/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: CRONO NACIONALIZACION A DOLAR DIVISA
   Actualiza la DESCRIPCION de comex_dias_bcra y comex_bcra_antiguedad_max
   ----------------------------------------------------------------------------
   Base    : central
   Tabla   : RO_T_CASHFLOW_PARAMETROS (no crea ninguna)
   Orden   : despues de sql/cashflow_comex_dolar_bcra.sql (el 45), que siembra
             las dos filas. Es el 50 de la instalacion completa
             (README-cashflow.md).
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Desde feature/crono-nacionalizacion-dolar-divisas, los dos parametros
   rigen el "dolar del dia anterior" de las tres pantallas, que ya no es
   siempre el BCRA: Proveedores Exterior y Compras Proyectadas usan el dolar
   oficial BCRA vendedor y Crono Nacionalizacion el dolar divisa vendedor
   (FP_DOLAR_DIVISAS). Parametros > Compras Exterior muestra la DESCRIPCION
   de la base debajo de cada campo, y la sembrada por el 45 dice "dolar BCRA"
   para todo. Este script la reescribe.

   LAS CLAVES NO CAMBIAN, a proposito: el codigo, el 45 y cualquier consulta
   que las lea siguen andando. Solo cambia el texto.

   SIN ESTE SCRIPT TODO FUNCIONA IGUAL. La regla, los rotulos de Parametros
   y su nota de ayuda vienen del codigo; lo unico que queda viejo es la linea
   de descripcion debajo de cada campo.

   LA COLUMNA ES VARCHAR(200): los dos textos nuevos tienen 192 y 162.

   NO TOCA EL VALOR. Solo reescribe la descripcion si todavia es la que
   sembro el 45, asi que no pisa una que alguien haya cambiado a mano. Es
   reejecutable.
   ============================================================================ */

UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
SET DESCRIPCION = 'Dias con dolar del dia anterior: lo que se paga dentro de estos dias -o vencido- va al dolar vendedor del dia habil anterior (BCRA; divisa en Crono Nacionalizacion); lo demas, a la curva ROFEX'
WHERE CLAVE = 'comex_dias_bcra'
  AND DESCRIPCION = 'Dias con dolar BCRA: los pagos en dolares de Comercio Exterior que salen dentro de estos dias -o ya vencidos- se pasan a pesos con el dolar oficial BCRA vendedor; los posteriores, con la curva ROFEX';

PRINT 'comex_dias_bcra: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + ' descripcion(es) actualizada(s).';

UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
SET DESCRIPCION = 'Antiguedad maxima del dolar del dia anterior: si la ultima cotizacion -BCRA o divisa, segun la pantalla- tiene mas de estos dias corridos, se usa igual y se avisa'
WHERE CLAVE = 'comex_bcra_antiguedad_max'
  AND DESCRIPCION = 'Antiguedad maxima del dolar BCRA: si la ultima cotizacion publicada tiene mas de estos dias corridos, se usa igual y se avisa';

PRINT 'comex_bcra_antiguedad_max: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + ' descripcion(es) actualizada(s).';
GO

PRINT 'Crono Nacionalizacion a dolar divisa: listo.';
GO
