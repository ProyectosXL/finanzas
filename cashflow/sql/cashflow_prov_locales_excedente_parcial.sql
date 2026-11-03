/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: PAGO CON EXCEDENTE Y PAGO PARCIAL
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales_pago_medio.sql: le agrega
             columnas a su tabla y aborta si no esta. Se puede correr en
             cualquier momento; sin el, la importacion funciona como antes
             (importe = neto a pagar con $1 de tolerancia) y la pestaña avisa.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Hasta aca el IMPORTE de la planilla de pagos tenia que ser el NETO A PAGAR
   del comprobante, con un peso de tolerancia. Pasa a haber dos casos mas:

     EXCEDENTE  se paga MAS que el neto, hasta un % parametrizable. En Tango la
                O/P cancela la factura y la diferencia queda A CUENTA del
                proveedor (la O/P queda en ESTADO 'CTA'). El cashflow proyecta
                el importe pagado completo: el excedente es plata que sale.
     PARCIAL    se paga MENOS que el neto. Lo pagado se proyecta en la fecha de
                la planilla y el saldo con la jerarquia de siempre. Una factura
                se puede pagar en N veces: cada planilla trae otro parcial
                contra lo que quede pendiente.

   ----------------------------------------------------------------------------
   1. DONDE SE GUARDA EL PAGO: LA TABLA DE MEDIOS, CON UNA PARTE O CON DOS

   RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO guardaba la apertura de un comprobante
   pagado con DOS medios. Ahora guarda tambien el pago de UN medio cuando su
   importe no es el neto: es lo que se va a proyectar, y sin guardarlo no hay
   de donde sacarlo. Un comprobante "neto" con un medio sigue sin filas aca, y
   funciona EXACTAMENTE como antes: no se migra nada.

   Con un medio, la forma sigue viviendo en la tabla de pagos -como siempre-,
   asi que FORMA_PAGO pasa a admitir NULL. El CHECK de ECHEQ/TRANSFERENCIA se
   deja como esta: un NULL lo pasa, y dos medios siguen exigiendo una de las
   dos (la regla con su mensaje esta en Proveedores::compararImportacion()).

   COLUMNAS NUEVAS, iguales en todas las partes de un comprobante:

     CLASIFICACION     NETO / EXCEDENTE / PARCIAL. Las filas que ya existen
                       son aperturas en dos medios que sumaban el neto: NETO.
     PENDIENTE_IMPORT  el pendiente de Tango del comprobante entero al
     NETO_IMPORT       importar, y su neto a pagar. Con eso se sabe despues si
                       Tango ya registro el pago parcial: su pendiente bajo.
                       NULL en las filas de antes, que siguen con la regla de
                       antes.
     ESTADO_TANGO      PREVISTO mientras Tango no registro el pago parcial;
     FECHA_REAL        REGISTRADO cuando lo registro, con la fecha real y el
     PENDIENTE_REAL    pendiente que dejo. LA PREVISION NO SE BORRA, como en la
     FECHA_REGISTRO    conciliacion: es lo que permite ver despues que hubo un
                       pago parcial y cuando se hizo de verdad.

   SIN BAJAS FISICAS, como hasta ahora: reimportar reemplaza el pago entero
   -las partes vigentes pasan a VIGENTE = 0 con quien y cuando- y el boton de
   volver al vencimiento lo da de baja.

   ----------------------------------------------------------------------------
   2. EL % DE EXCEDENTE PERMITIDO

   'prov_locales_pct_excedente' en RO_T_CASHFLOW_PARAMETROS, modulo
   PROV_LOCALES. Es UNO, general, y se calcula sobre el NETO A PAGAR -lo que
   sale de la caja-: con neto $1.000.000 y 5%, se acepta hasta $1.050.000.
   0 quiere decir "no se acepta excedente" y es un valor valido.

   Se edita en Parametros -> Prov. Locales, seccion Importacion de pagos, que
   lo valida (>= 0 y < 100, hasta dos decimales). Parametros::saveParametro()
   rechaza esta clave: guarda cualquier texto. Queda la ultima modificacion,
   con USUARIO_MODIF y FECHA_MODIF.

   Si el parametro ya existe NO se toca su VALOR: alguien pudo haberlo editado.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: las aperturas
   que ya estan quedan como NETO y se proyectan igual que antes.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO. Corre primero sql/cashflow_prov_locales_pago_medio.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. Con un medio la forma vive en la tabla de pagos: FORMA_PAGO admite NULL.
   ---------------------------------------------------------------------------- */
IF EXISTS (SELECT 1 FROM sys.columns
           WHERE object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO')
             AND name = 'FORMA_PAGO' AND is_nullable = 0)
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ALTER COLUMN FORMA_PAGO VARCHAR(30) NULL;
    PRINT 'FORMA_PAGO admite NULL.';
END
GO

/* ----------------------------------------------------------------------------
   2. Las columnas nuevas. Cada una en su lote: un CHECK no puede nombrar una
      columna que se agrega en el mismo lote.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'CLASIFICACION') IS NULL
BEGIN
    /* El DEFAULT rellena las filas que ya estan: son aperturas que sumaban el
       neto, y eso es NETO. */
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD CLASIFICACION VARCHAR(10) NOT NULL
        CONSTRAINT DF_RO_T_CF_PLMED_CLASIF DEFAULT ('NETO');
    PRINT 'Agregada CLASIFICACION.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_PLMED_CLASIF')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD CONSTRAINT CK_RO_T_CF_PLMED_CLASIF
        CHECK (CLASIFICACION IN ('NETO', 'EXCEDENTE', 'PARCIAL'));
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'PENDIENTE_IMPORT') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD
        PENDIENTE_IMPORT DECIMAL(18,2) NULL,
        NETO_IMPORT      DECIMAL(18,2) NULL;
    PRINT 'Agregadas PENDIENTE_IMPORT y NETO_IMPORT.';
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'ESTADO_TANGO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD
        ESTADO_TANGO   VARCHAR(10)   NOT NULL
            CONSTRAINT DF_RO_T_CF_PLMED_ESTTANGO DEFAULT ('PREVISTO'),
        FECHA_REAL     DATE          NULL,
        PENDIENTE_REAL DECIMAL(18,2) NULL,
        FECHA_REGISTRO DATETIME      NULL;
    PRINT 'Agregadas ESTADO_TANGO, FECHA_REAL, PENDIENTE_REAL y FECHA_REGISTRO.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_PLMED_ESTTANGO')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO ADD CONSTRAINT CK_RO_T_CF_PLMED_ESTTANGO
        CHECK (ESTADO_TANGO IN ('PREVISTO', 'REGISTRADO'));
END
GO

/* ----------------------------------------------------------------------------
   3. El % de excedente permitido, sembrado en 5 sin pisar uno que ya este.

   QUIEN LO CARGO: 'SISTEMA:' + el nombre del script, no NULL: NULL quiere
   decir "fila historica, de antes de la auditoria" (AuthCashflow::ORIGEN_SISTEMA).
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
               WHERE CLAVE = 'prov_locales_pct_excedente')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('prov_locales_pct_excedente', '5', 'DECIMAL',
                'Cuanto mas que el neto a pagar se acepta en la planilla de pagos, en %',
                'IMPORTACION');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS
                (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO, USUARIO_ALTA, USUARIO_MODIF)
            VALUES (''prov_locales_pct_excedente'', ''5'', ''DECIMAL'',
                    ''Cuanto mas que el neto a pagar se acepta en la planilla de pagos, en %'',
                    ''IMPORTACION'', ''PROV_LOCALES'',
                    ''SISTEMA:cashflow_prov_locales_excedente_parcial.sql'',
                    ''SISTEMA:cashflow_prov_locales_excedente_parcial.sql'');';

    PRINT 'Parametro prov_locales_pct_excedente sembrado en 5%.';
END
ELSE
BEGIN
    PRINT 'El parametro prov_locales_pct_excedente ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   4. Control: el % tiene que ser un numero >= 0 y < 100.

   Se pudo haber editado por fuera de la pantalla. Con un valor invalido la
   importacion no acepta ningun excedente y la pestaña lo avisa en critico.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE CLAVE = 'prov_locales_pct_excedente'
      AND (TRY_CAST(REPLACE(VALOR, ',', '.') AS DECIMAL(9,4)) IS NULL
           OR TRY_CAST(REPLACE(VALOR, ',', '.') AS DECIMAL(9,4)) < 0
           OR TRY_CAST(REPLACE(VALOR, ',', '.') AS DECIMAL(9,4)) >= 100)
)
BEGIN
    RAISERROR('ATENCION: prov_locales_pct_excedente no es un numero >= 0 y < 100. Corregilo en Parametros -> Prov. Locales: mientras tanto no se acepta ningun excedente.', 16, 1);
END
GO

SET NOEXEC OFF;
GO

PRINT 'Pago con excedente y pago parcial listos.';
GO
