/* ============================================================================
   RO_V_DOLAR_OFICIAL_BCRA
   Tipo de cambio de CIERRE de cada mes: una fila por anio/mes con la cotizacion
   del ultimo dia cargado de ese mes.

   Para el mes en curso devuelve la ultima cotizacion disponible, que es lo que
   necesita la pestana Venta Acumulada: cada mes se valua a SU propio tipo de
   cambio y los importes en dolares se suman recien despues.

   Es la unica lectura de tipo de cambio del cashflow: se accede siempre a
   traves de cashflow/Class/Cotizacion.php.
   ----------------------------------------------------------------------------
   EXPONE LAS DOS PUNTAS, Y EL LLAMADOR ELIGE

       Comprador AS TCC   lo que el banco paga por un dolar
       Vendedor  AS TCV   lo que el banco cobra por un dolar

   Esta vista decia antes que las dos vistas del modulo tenian que exponer la
   misma punta "o los numeros de dos pantallas del mismo modulo no cerrarian
   entre si". Eso era cierto mientras TODO se valuaba con comprador, y dejo de
   serlo: Dolares Cuenta Comitente valua con VENDEDOR y el resto del cashflow
   -Ventas, Saldos, Exportaciones Tasky, Comex- sigue con COMPRADOR.

   No cierran entre si A PROPOSITO, y por eso la pantalla que usa la punta
   distinta lo dice en su grilla: un numero valuado a vendedor que no diga que
   es a vendedor se compara contra el BCRA comprador y parece estar mal.

   La punta la elige el llamador en Cotizacion::ultimaHasta(), con comprador por
   defecto. Ninguna de las dos columnas se saca: quien lee esta vista pide la
   que necesita.
   ----------------------------------------------------------------------------
   Ya ejecutada en 'central'. Queda documentada porque es reejecutable y sirve
   para levantar el modulo en otra base.
   ============================================================================ */

IF OBJECT_ID('RO_V_DOLAR_OFICIAL_BCRA', 'V') IS NOT NULL
    DROP VIEW RO_V_DOLAR_OFICIAL_BCRA;
GO

CREATE VIEW RO_V_DOLAR_OFICIAL_BCRA AS
SELECT d.Fecha,
       d.Mes,
       d.Año,
       d.Comprador AS TCC,
       d.Vendedor  AS TCV
FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra d
INNER JOIN (
    SELECT Año, Mes, MAX(Fecha) AS Fecha
    FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra
    GROUP BY Año, Mes
) u ON d.Año = u.Año AND d.Mes = u.Mes AND d.Fecha = u.Fecha;
GO
