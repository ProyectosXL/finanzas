/* ============================================================================
   MODULO CASHFLOW - SALDOS: MERCADO PAGO SE LEE EN VIVO
   Pasa la cuenta de Mercado Pago de carga manual a consulta
   ----------------------------------------------------------------------------
   Base    : central
   Tabla   : RO_T_CASHFLOW_SALDOS_CUENTA (no crea ninguna)
   Orden   : en cualquier momento, despues de sql/cashflow_saldos.sql. Es el 43
             de la instalacion completa (README-cashflow.md).
   ----------------------------------------------------------------------------
   QUE RESUELVE

   El saldo de Mercado Pago se tipeaba en cada carga de la pestana Saldos. Ya
   lo trae un proceso a MP_T_SALDO_DEMANDA, asi que se lee de ahi: TOTAL_AMOUNT
   del ultimo registro por CREATED_AT, en la moneda de la cuenta. Lo hace
   Saldos::getSaldoMercadoPago(), y la pestana y el tablero lo leen en vivo cada
   vez que se dibujan, igual que el efectivo de tesoreria (SBA05).

   Lo unico que cambia en la base es el ORIGEN_DATO de la cuenta: MANUAL pasa a
   CONSULTA, que es lo que hace que la carga no pida tipearlo y que el codigo lo
   lea de su consulta. Las cuentas nuevas de tipo MERCADO_PAGO ya nacen como
   CONSULTA (Saldos::TIPOS_CONSULTA).

   SIN ESTE SCRIPT Mercado Pago sigue en carga manual, exactamente como hoy, y
   Parametros > Saldos dice que script falta.

   NO HAY BAJAS NI BORRADOS: las cargas manuales anteriores de Mercado Pago
   quedan en RO_T_CASHFLOW_SALDOS_DETALLE como historico, y son las que se usan
   si un dia la lectura en vivo falla.

   ES REEJECUTABLE: solo toca las cuentas que todavia no son CONSULTA.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U') IS NULL
BEGIN
    PRINT 'Falta RO_T_CASHFLOW_SALDOS_CUENTA: corre primero sql/cashflow_saldos.sql. No se hizo nada.';
END
ELSE
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_SALDOS_CUENTA
    SET ORIGEN_DATO = 'CONSULTA',
        USUARIO_MODIF = 'SISTEMA:cashflow_saldos_mp_consulta',
        FECHA_MODIF = GETDATE()
    WHERE TIPO = 'MERCADO_PAGO'
      AND ORIGEN_DATO <> 'CONSULTA';

    PRINT CAST(@@ROWCOUNT AS VARCHAR(10)) + ' cuenta(s) de Mercado Pago pasaron a leerse en vivo.';
END
GO

/* ----------------------------------------------------------------------------
   Control: de donde se va a leer. Sin la tabla, o sin datos, la pestana usa la
   ultima carga manual y lo avisa; esto lo dice al correr.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.MP_T_SALDO_DEMANDA', 'U') IS NULL
BEGIN
    PRINT 'ATENCION: no existe MP_T_SALDO_DEMANDA. Mientras no exista, Mercado Pago se muestra con su ultima carga manual y la pestana lo avisa.';
END
ELSE
BEGIN
    SELECT TOP 1 CURRENCY_ID, TOTAL_AMOUNT, CREATED_AT
    FROM dbo.MP_T_SALDO_DEMANDA
    ORDER BY CREATED_AT DESC, ID DESC;
END
GO

PRINT 'Mercado Pago en vivo: listo.';
GO
