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
   que fecha es. Rellenar aca escondería que el dato es del viernes.

   SE EXPONE 'Comprador' COMO TCC, igual que RO_V_DOLAR_OFICIAL_BCRA: los dos
   criterios tienen que valuar con la misma punta o los numeros de dos pantallas
   del mismo modulo no cerrarian entre si.

   ES REEJECUTABLE: se borra y se vuelve a crear.
   ============================================================================ */

IF OBJECT_ID('RO_V_DOLAR_OFICIAL_BCRA_DIARIO', 'V') IS NOT NULL
    DROP VIEW RO_V_DOLAR_OFICIAL_BCRA_DIARIO;
GO

CREATE VIEW RO_V_DOLAR_OFICIAL_BCRA_DIARIO AS
SELECT d.Fecha, d.Mes, d.Año, d.Comprador AS TCC
FROM [XL-APPS].sistemas.DBO.dolar_oficial_bcra d;
GO
