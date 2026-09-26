/* ============================================================================
   RO_V_DOLAR_OFICIAL_BCRA_DIARIO
   Tipo de cambio oficial del BCRA DIA POR DIA, tal como viene del origen.
   ----------------------------------------------------------------------------
   Base   : central (la tabla de origen esta en el servidor vinculado XL-APPS)
   Orden  : se puede correr en cualquier momento. Sin ella, Dolares Cuenta
            Comitente avisa y muestra la fila en cero.
   ----------------------------------------------------------------------------
   POR QUE HACE FALTA, SI YA EXISTE RO_V_DOLAR_OFICIAL_BCRA

   La vista que ya existia COLAPSA a una fila por anio/mes: se queda con el
   ultimo dia cargado de cada mes, o sea el tipo de cambio de CIERRE. Eso es
   exactamente lo que necesita Venta Acumulada, donde cada mes se valua a su
   propio cierre y los dolares se suman recien despues.

   Pero para decir cuanto valen HOY unos dolares que estan en una cuenta, el
   cierre del mes no sirve: el 15 de septiembre todavia no existe el cierre de
   septiembre, y usar el del mes de la carga valua con una cotizacion que puede
   ser de hace semanas sin que nada lo diga.

   Esta vista no colapsa nada: devuelve la serie diaria completa, y
   Cotizacion::ultimaHasta() se queda con la ultima cotizacion ANTERIOR O IGUAL
   a la fecha que se le pide, JUNTO CON LA FECHA DE ESA COTIZACION. La fecha es
   parte del dato: sin ella el importe en pesos del tablero no se puede explicar
   contra nada.

   LAS DOS VISTAS CONVIVEN Y LAS DOS SON CORRECTAS. Son dos preguntas distintas:
       cierre mensual -> "cuanto valio el dolar en ese mes"    (Ventas)
       ultima diaria  -> "cuanto vale hoy lo que tengo"        (Otros Ingresos)
   Ver el encabezado de cashflow/Class/Cotizacion.php.

   NO HAY RELLENO DE DIAS SIN COTIZACION. Los fines de semana y feriados no
   tienen fila, y esta vista tampoco los inventa: la ultima cotizacion conocida
   de un sabado es la del viernes, y eso lo resuelve quien consulta, diciendo de
   que fecha es. Rellenar aca esconderia que el dato es del viernes.
   ----------------------------------------------------------------------------
   EXPONE LAS DOS PUNTAS, Y EL LLAMADOR ELIGE

       Comprador AS TCC   lo que el banco paga por un dolar
       Vendedor  AS TCV   lo que el banco cobra por un dolar

   Esta vista exponia SOLO 'Comprador AS TCC', y el motivo escrito era que las
   dos vistas tenian que valuar con la misma punta "o los numeros de dos
   pantallas del mismo modulo no cerrarian entre si". Eso era cierto mientras
   todo el cashflow valuaba con comprador, y dejo de serlo.

   Dolares Cuenta Comitente valua con VENDEDOR: es la punta a la que se compra
   un dolar, y esa cuenta comitente se mide contra lo que costaria reponerla.
   El resto del modulo -Ventas, Saldos, Exportaciones Tasky, Comex- sigue con
   COMPRADOR y no se movio.

   Las dos pantallas NO cierran entre si, y es deliberado. Por eso la grilla de
   Dolares Comitente dice en pantalla con que punta se valuo cada fila: un
   numero a vendedor que no diga que es a vendedor se compara contra el BCRA
   comprador y parece un error.

   La punta la elige el llamador -Cotizacion::ultimaHasta($fecha, $punta)-, con
   comprador por defecto, asi que agregar la columna no movio a nadie.
   ----------------------------------------------------------------------------
   ES REEJECUTABLE: se borra y se vuelve a crear.
   ============================================================================ */

IF OBJECT_ID('RO_V_DOLAR_OFICIAL_BCRA_DIARIO', 'V') IS NOT NULL
    DROP VIEW RO_V_DOLAR_OFICIAL_BCRA_DIARIO;
GO

CREATE VIEW RO_V_DOLAR_OFICIAL_BCRA_DIARIO AS
SELECT d.Fecha,
       d.Mes,
       d.Año,
       d.Comprador AS TCC,
       d.Vendedor  AS TCV
FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra d;
GO
