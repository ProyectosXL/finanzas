/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: FORMA DE PAGO POR FACTURA
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales.sql.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La forma de pago del MAESTRO decide si la deuda de un proveedor entra al
   cronograma del cashflow -hoy entran ECHEQ y TRANSFERENCIA-. Es una propiedad
   del PROVEEDOR, y esta bien que lo sea: a este se le paga por transferencia, a
   aquel por caja.

   Pero una factura puntual puede pagarse distinto sin que eso cambie como se le
   paga al proveedor en general. Hasta ahora no habia donde decirlo: o se
   cambiaba el maestro -y se movia toda la deuda de ese proveedor- o no se decia.

   ----------------------------------------------------------------------------
   POR QUE UNA COLUMNA NUEVA Y NO LA FORMA_PAGO QUE YA ESTABA

   Porque son DOS COSAS DISTINTAS, y el modulo ya las distinguia:

     FORMA_PAGO              UN HECHO: por que via salio o va a salir ese pago.
                             LA ESCRIBE LA IMPORTACION DE LA PLANILLA DE PAGOS,
                             en todas sus filas. Solo se muestra.

     FORMA_PAGO_CRONOGRAMA   UNA REGLA: con que forma hay que tratar a ESTA
                             factura para decidir si entra al cashflow. La pone
                             una persona desde la grilla, factura por factura.

   Si la que decidiera fuera FORMA_PAGO, importar la planilla de pagos pasaria a
   mover comprobantes dentro y fuera del cashflow, porque esa importacion le
   escribe la forma a todas sus filas. Nadie habria pedido esa reclasificacion.
   Al dia de escribir esto no hay ni un comprobante donde las dos difieran, asi
   que el dano no se veria hasta la primera planilla que traiga una via distinta
   de la habitual.

   La regla del modulo no cambia -EL FILTRO MIRA UNA REGLA, NO UN HECHO-: lo que
   se agrega es un escalon mas fino de la misma regla.

       CRONOGRAMA = esDelCronograma( override de la factura
                                     ?? forma del maestro )

   NO TOCA EL MAESTRO. Poner un override no cambia RO_T_CASHFLOW_PROV_LOCALES_CATEG
   ni la clasificacion de las otras facturas del mismo proveedor.

   ----------------------------------------------------------------------------
   FECHA_PAGO PASA A SER NULLABLE, Y ES PARTE DE LO MISMO

   Esta tabla nacio como "las fechas de pago" y pasa a ser "los overrides de este
   comprobante": la fecha, la forma con la que se lo trata, y -con el script
   siguiente- si se lo excluye.

   Con FECHA_PAGO NOT NULL no habia forma de guardar un override sin inventarle
   ademas una fecha de pago al comprobante, y una fecha inventada no es un dato
   que falte: es un dato falso que despues alguien lee como una decision.

   Una fila sin fecha de pago cae sola al escalon siguiente de la jerarquia -el
   vencimiento de Tango-, que es exactamente lo que pasaba cuando no habia fila.
   Ver Proveedores::resolverFechaPago().

   ----------------------------------------------------------------------------
   LAS FILAS QUE YA ESTAN

   Quedan con el override en NULL, o sea "usa la forma del maestro": el dia que
   se corra esto el tablero no se mueve ni un peso. Al escribirlo son 11 filas.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_PAGO. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La fecha de pago deja de ser obligatoria.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO')
      AND name = 'FECHA_PAGO'
      AND is_nullable = 0
)
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ALTER COLUMN FECHA_PAGO DATE NULL;
END
GO

/* ----------------------------------------------------------------------------
   2. El override por factura.

   VARCHAR(30) y sin columna _ORIG, a diferencia de FORMA_PAGO: esta no se tipea
   ni viene de una planilla sucia, se elige de la lista de FORMAS_PAGO en un
   desplegable. No hay un "original" que conservar porque no hay nada que
   normalizar.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'FORMA_PAGO_CRONOGRAMA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ADD FORMA_PAGO_CRONOGRAMA VARCHAR(30) NULL;
END
GO

/* ----------------------------------------------------------------------------
   3. Control: una fila sin nada adentro no tiene razon de existir.

   Si esto devuelve filas, quedo un override vacio -se puso y se saco- y lo que
   corresponde es borrarlo, no dejarlo. El circuito lo hace solo; esto detecta
   una edicion a mano sobre la base.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
    WHERE FECHA_PAGO IS NULL
      AND FORMA_PAGO_CRONOGRAMA IS NULL
      AND FORMA_PAGO IS NULL
      AND OBSERVACION IS NULL
)
BEGIN
    PRINT 'AVISO: hay filas de override sin ningun dato. No rompen nada, pero se pueden borrar.';
END
GO

PRINT 'Forma de pago por factura lista.';
GO
