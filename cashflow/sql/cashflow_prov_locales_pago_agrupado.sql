/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: UN CHEQUE PARA VARIAS FACTURAS
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales_pago_n_medios.sql (el
             decimotercero), que a su vez va despues del undecimo: aborta si
             el CHECK de ORDEN todavia es el de dos partes. Se puede correr en
             cualquier momento; sin el, la planilla de pagos funciona como antes
             -cada fila es su factura, vacio es el neto y 0 es error- y la
             pestaña avisa que falta.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Un proveedor con muchas facturas chicas se paga con UN cheque (o una
   transferencia). En la planilla son las filas de esas facturas, del mismo
   proveedor, con la misma FECHA_PAGO y la misma FORMA_PAGO: una trae el
   importe del cheque y las demas vienen en 0 -o vacias, si el importe supera
   el maximo de su propia factura-. Eso es un PAGO AGRUPADO.

   Antes el importe del cheque quedaba en la primera factura, con un sobrepago
   enorme, y las demas no se podian cargar. Ahora el pago se reparte entre las
   facturas del grupo, en orden de vencimiento: cada una guarda SU PARTE -su
   neto, y el excedente en la ultima-, y todas llevan la marca del grupo.

   ----------------------------------------------------------------------------
   DONDE VIVE: UNA COLUMNA EN LA TABLA DE MEDIOS, Y NADA MAS

   GRUPO_PAGO en RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO: el identificador del
   pago agrupado, 'COD_PROVEE|AAAA-MM-DD|FORMA'. Cada factura del grupo tiene su
   propia parte -una fila- con su importe, su clasificacion y la fecha del
   pago, como cualquier pago importado. Por eso la grilla, el tablero y la
   conciliacion siguen trabajando POR FACTURA sin codigo nuevo.

   EL TOTAL DEL CHEQUE NO SE GUARDA: es la suma de las partes vigentes con el
   mismo GRUPO_PAGO. Guardarlo aparte seria una segunda copia que se puede
   desincronizar de las partes; asi es imposible que el cheque diga una cosa y
   sus facturas otra.

   EL IDENTIFICADOR SE PUEDE LEER Y ES ESTABLE: es proveedor, fecha y forma,
   que es justamente lo que define el grupo en la planilla (no trae numero de
   cheque ni de orden de pago). Reimportar el mismo pago da el mismo
   identificador, y reimportar una de sus facturas sabe a que grupo reemplaza.
   Collation Latin1_General_BIN, como la clave del comprobante: el codigo de
   proveedor va adentro.

   EL INDICE FILTRADO es el de las dos preguntas que se le hacen: "que facturas
   tiene este pago agrupado" (para el tooltip y para deshacerlo con el boton de
   volver al vencimiento) sobre las partes vigentes.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS, COMO SIEMPRE

   Reimportar una factura del grupo reemplaza el grupo ENTERO, y el boton de
   volver al vencimiento lo deshace entero: las partes vigentes pasan a
   VIGENTE = 0 con quien y cuando, igual que cualquier apertura.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: la columna nace
   en NULL en todas las filas, y una parte sin grupo es lo que era.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PLMED_ORDEN'
                 AND definition LIKE '%>=%')
BEGIN
    RAISERROR('La tabla de medios todavia acepta dos partes como maximo. Corre primero sql/cashflow_prov_locales_pago_n_medios.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. La columna del grupo.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'GRUPO_PAGO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO
        ADD GRUPO_PAGO VARCHAR(60) COLLATE Latin1_General_BIN NULL;

    PRINT 'Agregada GRUPO_PAGO a dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO.';
END
ELSE
BEGIN
    PRINT 'GRUPO_PAGO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. Las facturas de un pago agrupado, vigentes.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_RO_T_CF_PLMED_GRUPO'
                 AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLMED_GRUPO
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO (GRUPO_PAGO)
        INCLUDE (COD_PROVEE, T_COMP, N_COMP)
        WHERE VIGENTE = 1 AND GRUPO_PAGO IS NOT NULL;

    PRINT 'Creado IX_RO_T_CF_PLMED_GRUPO.';
END
GO

/* ----------------------------------------------------------------------------
   3. Control: la columna quedo.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'GRUPO_PAGO') IS NULL
BEGIN
    RAISERROR('ATENCION: no se agrego GRUPO_PAGO. Revisa el paso 1.', 16, 1);
END
ELSE
BEGIN
    PRINT 'Pago agrupado listo.';
END
GO

SET NOEXEC OFF;
GO
