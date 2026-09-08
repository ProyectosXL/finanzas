/* ============================================================================
   RO_V_DOLAR_OFICIAL_BCRA
   Tipo de cambio de CIERRE de cada mes: una fila por anio/mes con la cotizacion
   del ultimo dia cargado de ese mes.

   Para el mes en curso devuelve la ultima cotizacion disponible, que es lo que
   necesita la pestana Venta Acumulada: cada mes se valua a SU propio tipo de
   cambio y los importes en dolares se suman recien despues.

   Es la unica lectura de tipo de cambio del cashflow: se accede siempre a
   traves de cashflow/Class/Cotizacion.php.

   Ya ejecutada en 'central'. Queda documentada porque es reejecutable y sirve
   para levantar el modulo en otra base.
   ============================================================================ */

IF OBJECT_ID('RO_V_DOLAR_OFICIAL_BCRA', 'V') IS NOT NULL
    DROP VIEW RO_V_DOLAR_OFICIAL_BCRA;
GO

CREATE VIEW RO_V_DOLAR_OFICIAL_BCRA AS
SELECT d.Fecha, d.Mes, d.Año, d.Comprador AS TCC
FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra d
INNER JOIN (
    SELECT Año, Mes, MAX(Fecha) AS Fecha
    FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra
    GROUP BY Año, Mes
) u ON d.Año = u.Año AND d.Mes = u.Mes AND d.Fecha = u.Fecha;
GO
