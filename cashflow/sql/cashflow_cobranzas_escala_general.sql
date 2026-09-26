/* ============================================================================
   MODULO COBRANZAS FR - ESCALA DE DESCUENTO GENERAL
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : puede correrse en cualquier momento; no depende de los otros scripts
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Hasta ahora la escala de descuento se cargaba POR CLIENTE en
   RO_T_CASHFLOW_COBRANZAS_PARAM_DESC, con tramo de dias y medio de pago. Eso
   obliga a configurar una escala por cada franquicia, y en la practica la
   escala comercial es UNA SOLA para todas. Lo que se conseguia con la escala
   por cliente era un formulario largo y un monton de clientes sin escala
   cargada cayendo a un porcentaje de respaldo distinto.

   Ahora hay una unica escala, sin cliente y SIN MEDIO DE PAGO. El medio de
   pago dejo de entrar en el calculo: el mismo tramo rige para todos.
   MEDIO_PAGO_DEFAULT de RO_T_PARAMETROS_DESC_CLIENTES queda como dato
   informativo del cliente.

   RO_T_CASHFLOW_COBRANZAS_PARAM_DESC NO SE BORRA
   Deja de leerse, pero queda con sus datos. Si alguna vez hace falta volver a
   escalas por cliente, el historico esta. Es el mismo criterio de baja logica
   que usa el resto del modulo.

   LOS TRAMOS TIENEN QUE CUBRIR TODO
   La semilla llega hasta 9999 dias a proposito. Un dia sin tramo devuelve 0% y
   eso es indistinguible de "el tramo dice 0%": el ultimo tramo abierto hace que
   el cero sea siempre una decision cargada y no un hueco de configuracion.

   ES REEJECUTABLE: la tabla se crea solo si no existe y la semilla entra por
   MERGE sobre (DIAS_DESDE, DIAS_HASTA), asi que una segunda corrida no duplica
   tramos ni pisa un porcentaje ya editado.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC (
        ID              INT IDENTITY(1,1) NOT NULL,
        DIAS_DESDE      INT           NOT NULL,
        DIAS_HASTA      INT           NOT NULL,
        PORCENTAJE_DESC DECIMAL(5,2)  NOT NULL,
        ACTIVO          BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_COB_ESCALA_ACTIVO DEFAULT (1),
        USUARIO         VARCHAR(50)   NULL,
        FECHA_MOD       DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_COB_ESCALA_RANGO CHECK (DIAS_DESDE >= 0 AND DIAS_HASTA >= DIAS_DESDE),
        CONSTRAINT CK_RO_T_CF_COB_ESCALA_PORC  CHECK (PORCENTAJE_DESC >= 0 AND PORCENTAJE_DESC <= 100)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COB_ESCALA_TRAMO
        ON dbo.RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC (ACTIVO, DIAS_DESDE, DIAS_HASTA)
        INCLUDE (PORCENTAJE_DESC);
END
GO

/* ---- Semilla: la escala comercial vigente -------------------------------- */
MERGE dbo.RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC AS T
USING (VALUES
    (   0,   20, 8.00),
    (  21,   30, 6.00),
    (  31,   45, 4.00),
    (  46, 9999, 0.00)
) AS S (DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC)
    ON T.DIAS_DESDE = S.DIAS_DESDE AND T.DIAS_HASTA = S.DIAS_HASTA
WHEN NOT MATCHED BY TARGET THEN
    INSERT (DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC, ACTIVO, FECHA_MOD)
    VALUES (S.DIAS_DESDE, S.DIAS_HASTA, S.PORCENTAJE_DESC, 1, GETDATE());
GO

PRINT 'Escala de descuento general lista.';
GO
