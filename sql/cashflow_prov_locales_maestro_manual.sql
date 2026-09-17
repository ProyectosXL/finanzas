/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: EL MAESTRO SE PUEDE CARGAR A MANO
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales.sql.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   El maestro solo se podia cargar importando la planilla. Ahora tambien se
   carga y se edita desde la pestana, proveedor por proveedor.

   El caso que lo pide es concreto: aparecen proveedores nuevos en Tango, nadie
   los agrega a la planilla, y su deuda queda sin clasificar. Al escribir esto
   son 20 proveedores con deuda por $17.011.478,01 que el control de faltantes
   ya venia listando sin que hubiera forma de resolverlos desde la pantalla.

   ----------------------------------------------------------------------------
   POR QUE HACE FALTA UNA COLUMNA, SI EDITAR YA SE PODIA HACER SIN ELLA

   Porque a partir de ahora hay DOS fuentes escribiendo la misma tabla, y el
   README de este modulo descarto explicitamente tener dos maestros en paralelo
   -"dos maestros en paralelo terminan discrepando"-.

   La decision es que LA PLANILLA SIGUE MANDANDO: una edicion manual es una
   version mas, y la proxima importacion la pisa como pisa cualquier otra. Eso
   mantiene una sola fuente de verdad.

   Pero pisar el trabajo de alguien sin decirselo es otra cosa. ORIGEN es lo que
   permite que la previsualizacion del diff avise "vas a pisar N filas que se
   editaron a mano" ANTES de confirmar, y que el historial del proveedor diga de
   donde salio cada version. Sin la columna, una edicion manual y una
   importacion son indistinguibles despues del hecho, que es justamente lo que
   el historial existe para evitar.

   ----------------------------------------------------------------------------
   LAS FILAS QUE YA ESTAN

   Quedan en 'IMPORT', que es lo que son: todo lo que hay hoy entro por la
   planilla. El default tambien es 'IMPORT' para que una instalacion que corra
   este script y no actualice el codigo siga insertando un valor valido.

   NO SE TOCA NINGUN OTRO DATO. El maestro no se recalcula ni se reimporta: solo
   se agrega la columna y se marca el origen de lo que ya estaba.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_CATEG. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La columna, con su default.

   VARCHAR(10) y no un BIT: 'IMPORT' / 'MANUAL' se leen solos en una consulta a
   mano, y un bit llamado ES_MANUAL obliga a recordar de que lado es cual. Es el
   mismo criterio -y el mismo nombre- que la columna ORIGEN que
   RO_T_CASHFLOW_PROV_LOCALES_PAGO ya tiene.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'ORIGEN') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG
        ADD ORIGEN VARCHAR(10) NOT NULL
            CONSTRAINT DF_RO_T_CF_PROVCAT_ORIGEN DEFAULT ('IMPORT');
END
GO

/* ----------------------------------------------------------------------------
   2. Lo que ya estaba entro por la planilla.

   El ADD con DEFAULT ya rellena las filas existentes, pero el UPDATE va igual y
   es barato: una instalacion que hubiera agregado la columna a mano sin default
   quedaria con NULLs, y una fila sin origen no se puede mostrar en el historial.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG
SET ORIGEN = 'IMPORT'
WHERE ORIGEN IS NULL OR LTRIM(RTRIM(ORIGEN)) = '';
GO

/* ----------------------------------------------------------------------------
   3. Control: el origen solo puede ser uno de los dos.

   Sin esto, un typo en un UPDATE a mano deja filas con un origen que la pantalla
   no sabe dibujar, y el sintoma es una fila del historial sin etiqueta.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG
    WHERE ORIGEN NOT IN ('IMPORT', 'MANUAL')
)
BEGIN
    RAISERROR('ATENCION: hay filas del maestro con un ORIGEN que no es IMPORT ni MANUAL.', 16, 1);
END
GO

PRINT 'Maestro de proveedores locales listo para carga manual.';
GO
