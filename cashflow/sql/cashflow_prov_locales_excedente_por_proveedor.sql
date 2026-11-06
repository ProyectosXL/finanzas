/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: EL EXCEDENTE SE VALIDA POR PROVEEDOR
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales_excedente_parcial.sql:
             cambia un CHECK que ese crea, y aborta si no esta. Se puede correr
             en cualquier momento; sin el, un proveedor que necesita compensar
             una factura con otra queda en error con un motivo que nombra este
             script, y todo lo demas funciona igual.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   El % de excedente de la planilla de pagos se validaba factura por factura.
   La orden de pago se arma POR PROVEEDOR: el redondeo o el excedente se pone
   en una factura y otra puede ir de menos. Ahora se valida sobre el total del
   proveedor, y cuando el total esta dentro del maximo ninguna factura queda
   con saldo: las que se pagan de mas o de menos quedan COMPENSADAS.

   Una COMPENSADA se guarda como un excedente o un parcial: en
   RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO, con el IMPORTE de la planilla, el
   pendiente y el neto al importar, y la fecha de la planilla en la tabla de
   pagos. Se proyecta exactamente el importe de la planilla, sin saldo, y la
   diferencia con su neto -con signo- es su parte del excedente del proveedor.

   LO UNICO QUE HACE FALTA EN LA BASE es que CLASIFICACION acepte el valor
   nuevo: el CHECK CK_RO_T_CF_PLMED_CLASIF pasa de NETO / EXCEDENTE / PARCIAL
   a NETO / EXCEDENTE / PARCIAL / COMPENSADA. La columna es VARCHAR(10), y
   'COMPENSADA' tiene diez letras: entra justo.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: no cambia
   ninguna fila, solo lo que se puede guardar de aca en adelante.

   ES REEJECUTABLE: si el CHECK ya acepta COMPENSADA no hace nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'CLASIFICACION') IS NULL
BEGIN
    RAISERROR('Falta la columna CLASIFICACION de RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO. Corre primero sql/cashflow_prov_locales_excedente_parcial.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. El CHECK de CLASIFICACION acepta COMPENSADA. Se reemplaza en una
      transaccion: nunca queda la tabla sin el CHECK.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PLMED_CLASIF'
                 AND definition LIKE '%COMPENSADA%')
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_PLMED_CLASIF')
        ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO DROP CONSTRAINT CK_RO_T_CF_PLMED_CLASIF;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD CONSTRAINT CK_RO_T_CF_PLMED_CLASIF
        CHECK (CLASIFICACION IN ('NETO', 'EXCEDENTE', 'PARCIAL', 'COMPENSADA'));

    COMMIT TRANSACTION;

    PRINT 'CLASIFICACION acepta COMPENSADA.';
END
ELSE
BEGIN
    PRINT 'CLASIFICACION ya aceptaba COMPENSADA: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. Control: el CHECK quedo y acepta el valor nuevo.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PLMED_CLASIF'
                 AND definition LIKE '%COMPENSADA%')
BEGIN
    RAISERROR('ATENCION: el CHECK CK_RO_T_CF_PLMED_CLASIF no acepta COMPENSADA. Revisa el paso 1.', 16, 1);
END
GO

SET NOEXEC OFF;
GO

PRINT 'Excedente por proveedor listo.';
GO
