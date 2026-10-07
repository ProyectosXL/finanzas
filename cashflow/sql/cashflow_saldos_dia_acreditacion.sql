/* ============================================================================
   CASHFLOW - SALDOS: dia de acreditacion / envio de cada local
   ============================================================================
   Base: central. Reejecutable. No borra ni pisa datos.

   POR QUE EXISTE
   --------------
   Cada local propio deposita su efectivo -o lo envia- un dia fijo de la
   semana. Hasta aca la fila Caja Locales del tablero ponia todo el aporte de
   los locales en la primera columna del eje, como si se acreditara hoy. Con el
   dia cargado, el aporte de cada local se imputa en su PROXIMA FECHA DE
   ACREDITACION: la primera vez que ese dia de la semana cae hoy o despues,
   corrida al habil siguiente si es feriado. La regla vive en
   Class/Saldos.php (proximaFechaAcreditacion()).

   1. RO_T_CASHFLOW_SALDOS_SUCURSAL.DIA_ACREDITACION
      El PARAMETRO: 1 = lunes ... 5 = viernes, ISO, como date('N'). Se edita
      solo en Parametros -> Saldos -> Locales.
        - En 'DEPOSITA' es el dia de acreditacion y decide la columna del
          tablero.
        - En 'ENVIA' es el dia de envio y es informativo: esos locales no
          aportan al cashflow.
      SIN DEFAULT, A PROPOSITO. Un local sin dia queda en NULL y la pantalla y
      el tablero lo avisan; un default pondria a todos los locales en el mismo
      dia y esconderia que falta cargarlo. La sincronizacion con
      SUCURSALES_LAKERS no lo toca, igual que GESTION y RESERVA.

   2. RO_T_CASHFLOW_SALDOS_LOCAL.DIA_ACREDITACION y FECHA_ACREDITACION
      La FOTO de cada carga guarda el dia EFECTIVO y la fecha CALCULADA de cada
      local, con el mismo criterio que GESTION y RESERVA: si alguien cambia el
      dia despues, una carga vieja se reconstruye tal como entro al tablero.
      Las cargas anteriores a este script quedan en NULL: no se sabe que dia
      tenian, y un valor inventado diria que si.

   Sin este script la pantalla funciona como antes: el dia no se puede elegir,
   la caja de los locales va a la primera columna y la pantalla avisa que
   script correr.

   El mismo bloque va tambien dentro de sql/cashflow_saldos.sql (5.c):
   alcanza con correr cualquiera de los dos.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL. Corre primero sql/cashflow_saldos.sql: este script le agrega columnas a sus tablas.', 16, 1);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'DIA_ACREDITACION') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL
        ADD DIA_ACREDITACION TINYINT NULL
            CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_SUCURSAL_DIA
            CHECK (DIA_ACREDITACION BETWEEN 1 AND 5);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'DIA_ACREDITACION') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL
        ADD DIA_ACREDITACION TINYINT NULL
            CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_DIA
            CHECK (DIA_ACREDITACION BETWEEN 1 AND 5);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'FECHA_ACREDITACION') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_LOCAL
        ADD FECHA_ACREDITACION DATE NULL;
END
GO

PRINT 'Saldos: dia de acreditacion por local listo.';
GO
