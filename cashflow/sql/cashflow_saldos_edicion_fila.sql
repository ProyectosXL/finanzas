/* ============================================================================
   MODULO CASHFLOW - SALDOS: EL SALDO SE EDITA DESDE SU FILA
   Cada edicion de una cuenta del catalogo es una carga propia, y reemplazarla
   deja el historial encadenado
   ----------------------------------------------------------------------------
   Base    : central
   Tabla   : RO_T_CASHFLOW_SALDOS_CARGA (agrega una columna; no crea ninguna)
   Orden   : en cualquier momento, despues de sql/cashflow_saldos.sql y de
             sql/cashflow_auditoria_usuario.sql. Ver README-saldos.md,
             "Ejecucion de los scripts".
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Desde feature/saldos-edicion-fila-ultimo-raf ya no hay "Nueva carga": el
   saldo de cada cuenta se edita desde su fila en Saldos > Saldos. Para las
   cuentas del catalogo (Mercado Pago, los bancos sin Interbanking y Otros)
   cada edicion es UNA CARGA CON UNA SOLA CUENTA: cabecera ORIGEN = 'MANUAL'
   en RO_T_CASHFLOW_SALDOS_CARGA y una fila ORIGEN_DATO = 'MANUAL' en
   RO_T_CASHFLOW_SALDOS_DETALLE. Asi el historico sigue siendo el de siempre,
   y getSaldosActuales() lo lee sin cambios.

   Dos ediciones de la misma cuenta el mismo dia: manda la ultima, y la
   anterior queda en el historial. Para eso la nueva da de baja la anterior
   (ACTIVO = 0, con USUARIO_BAJA y FECHA_BAJA) y la nombra en ID_REEMPLAZA,
   que es lo que agrega este script. Es el mismo circuito que el respaldo de
   Interbanking (RO_T_CASHFLOW_SALDOS_BANCO_MANUAL) y que los movimientos de
   los fondos. Quitar el manual de hoy da de baja su carga y no inserta nada.

   Las cuentas de Interbanking no usan esta tabla: su manual sigue en
   RO_T_CASHFLOW_SALDOS_BANCO_MANUAL (sql/cashflow_saldos_interbanking.sql).

   SIN ESTE SCRIPT la pestana funciona y las cuentas de Interbanking se editan
   igual; las del catalogo no ofrecen editar y la pestana dice que script falta.

   NO HAY BAJAS FISICAS NI BORRADOS. Las cargas anteriores, incluidas las de
   "Nueva carga" (ORIGEN = 'MIXTA'), quedan como estan, con ID_REEMPLAZA NULL.

   ES REEJECUTABLE: la columna y la FK se agregan solo si faltan.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'U') IS NULL
BEGIN
    PRINT 'Falta RO_T_CASHFLOW_SALDOS_CARGA: corre primero sql/cashflow_saldos.sql. No se hizo nada.';
END
ELSE IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'USUARIO_BAJA') IS NULL
BEGIN
    PRINT 'Falta USUARIO_BAJA en RO_T_CASHFLOW_SALDOS_CARGA: corre primero sql/cashflow_auditoria_usuario.sql. No se hizo nada.';
END
ELSE IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'ID_REEMPLAZA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CARGA ADD ID_REEMPLAZA INT NULL;

    PRINT 'Agregada RO_T_CASHFLOW_SALDOS_CARGA.ID_REEMPLAZA.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_SALDOS_CARGA.ID_REEMPLAZA ya existia: no se toco.';
GO

/* La carga que reemplaza apunta a la que dio de baja, y tiene que existir. */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'ID_REEMPLAZA') IS NOT NULL
   AND OBJECT_ID('dbo.FK_RO_T_CASHFLOW_SALDOS_CARGA_REEMPLAZA', 'F') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CARGA
        ADD CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_CARGA_REEMPLAZA
            FOREIGN KEY (ID_REEMPLAZA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_CARGA (ID);

    PRINT 'Agregada FK_RO_T_CASHFLOW_SALDOS_CARGA_REEMPLAZA.';
END
GO

PRINT 'Edicion de saldos desde la fila: listo.';
GO
