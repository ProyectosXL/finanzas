/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: N PAGOS POR COMPROBANTE
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales_excedente_parcial.sql (el
             undecimo): cambia un CHECK de la tabla de medios y aborta si no
             estan sus columnas del excedente. Se puede correr en cualquier
             momento; sin el, la planilla de pagos funciona como antes -hasta
             dos medios por comprobante, y uno solo si esta en cuotas- y la
             pestaña avisa que falta.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Un comprobante se paga en N filas de la planilla, cada una con su fecha, su
   forma y su importe: tres echeqs en tres fechas, un echeq y dos
   transferencias, y tambien un comprobante en cuotas, que se cubre de la cuota
   mas vieja a la mas nueva. Antes el tope era dos: el CHECK de ORDEN decia
   ORDEN IN (1, 2), y una tercera fila dejaba todo el comprobante en error.

   LO UNICO QUE HACE FALTA EN LA BASE es que ORDEN acepte mas de dos partes:
   el CHECK CK_RO_T_CF_PLMED_ORDEN pasa a ORDEN >= 1. ORDEN es TINYINT, asi que
   el techo fisico es 255.

   EL TOPE DE VERDAD ESTA EN EL CODIGO (Proveedores::MAX_PARTES, 50), no aca:
   mas de cincuenta filas de una misma factura es casi seguro un copiar y pegar
   de mas, y el codigo lo rechaza con un motivo que se lee. Un CHECK con el
   mismo numero seria una segunda copia del tope que se desincroniza en el
   primer cambio.

   EL INDICE UNICO FILTRADO NO CAMBIA: (COD_PROVEE, T_COMP, N_COMP, ORDEN)
   WHERE VIGENTE = 1 ya impide dos partes vigentes con el mismo orden, con dos
   o con cincuenta.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: no cambia
   ninguna fila, solo lo que se puede guardar de aca en adelante.

   ES REEJECUTABLE: si el CHECK ya acepta mas de dos partes no hace nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'CLASIFICACION') IS NULL
BEGIN
    RAISERROR('Falta la columna CLASIFICACION de RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO. Corre primero sql/cashflow_prov_locales_pago_medio.sql y sql/cashflow_prov_locales_excedente_parcial.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. El CHECK de ORDEN acepta N partes. Se reemplaza en una transaccion:
      nunca queda la tabla sin el CHECK.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PLMED_ORDEN'
                 AND definition LIKE '%>=%')
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_PLMED_ORDEN')
        ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO DROP CONSTRAINT CK_RO_T_CF_PLMED_ORDEN;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD CONSTRAINT CK_RO_T_CF_PLMED_ORDEN
        CHECK (ORDEN >= 1);

    COMMIT TRANSACTION;

    PRINT 'ORDEN acepta N partes por comprobante.';
END
ELSE
BEGIN
    PRINT 'ORDEN ya aceptaba N partes: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. Control: el CHECK quedo y acepta mas de dos partes.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PLMED_ORDEN'
                 AND definition LIKE '%>=%')
BEGIN
    RAISERROR('ATENCION: el CHECK CK_RO_T_CF_PLMED_ORDEN no acepta mas de dos partes. Revisa el paso 1.', 16, 1);
END
ELSE
BEGIN
    PRINT 'N pagos por comprobante listos.';
END
GO

SET NOEXEC OFF;
GO
