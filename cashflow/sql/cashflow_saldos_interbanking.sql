/* ============================================================================
   CASHFLOW - SALDOS: parametros de los bancos que vienen por Interbanking
   ============================================================================
   Base    : central. Reejecutable.
   Orden   : DESPUES de sql/cashflow_saldos_borrar_bancos_manuales.sql y antes
             de publicar el codigo de feature/saldos-interbanking. Ver
             README-saldos.md, "Ejecucion de los scripts".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   Los saldos bancarios dejan de tipearse: se leen en vivo de
   BI_T_SALDOS_INTERBANKING, que alimenta un proceso externo a este repo (ver
   Class/SaldosInterbanking.php). Ese origen trae el numero de banco y de
   cuenta, pero no dice como quiere el negocio ver cada banco ni cuales se
   siguen operando. Eso es lo que guarda este script, y NADA MAS: los saldos
   no se copian a ninguna tabla propia, porque la de BI ya tiene el historico
   y dos copias del mismo dato terminan discrepando.

   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_SALDOS_BANCO   alias y estado de cada banco (parametro)

   ----------------------------------------------------------------------------
   UN BANCO SIN FILA ES UN BANCO ACTIVO Y SIN ALIAS
   La lectura NUNCA escribe: un banco que aparece por primera vez en
   Interbanking entra al disponible sin que nadie lo de de alta. La fila se
   crea, como UPSERT, recien cuando alguien guarda alias o estado desde
   Parametros -> Saldos. Si dependiera de un alta, un banco nuevo quedaria
   afuera del disponible en silencio hasta que alguien se diera cuenta.

   LA CLAVE VA CON LA COLLATION DEL ORIGEN
   NRO_BANCO usa Modern_Spanish_CI_AI, igual que la tabla de BI, para que el
   dato compare igual de los dos lados aunque el cruce se haga en PHP.

   NO HAY BAJAS FISICAS: un banco se inhabilita con ACTIVO = 0 y sigue
   viendose en Parametros, para poder reactivarlo.

   Sin este script la pantalla no falla: todos los bancos se muestran activos y
   sin alias, y Parametros dice que script falta.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_SALDOS_BANCO
   Un banco por NRO_BANCO, como lo trae Interbanking ('007', '014'...).

   ALIAS es DEL BANCO, no de cada cuenta: todas sus cuentas lo muestran. Existe
   porque Tango guarda la razon social o un nombre viejo ("RIO DE LA PLATA
   S.A." es Santander, "FRANCES" es BBVA) y el negocio no lo reconoce. NULL
   vuelve a mostrar BANCO.DESC_BANCO; un alias de puros espacios se guarda NULL.

   ACTIVO = 0 saca al banco ENTERO de la pestana y del tablero, y lo calla:
   ni cuentas nuevas, ni saldo viejo, ni saldo faltante. Es para un banco que
   ya no se opera (hoy, Supervielle) y que Interbanking sigue informando.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_BANCO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_BANCO (
        NRO_BANCO     VARCHAR(10)  COLLATE Modern_Spanish_CI_AI NOT NULL,
        ALIAS         VARCHAR(100) COLLATE Modern_Spanish_CI_AI NULL,
        ACTIVO        BIT          NOT NULL CONSTRAINT DF_CF_SAL_BCO_ACTIVO DEFAULT (1),

        USUARIO_ALTA  VARCHAR(50)  NULL,
        FECHA_ALTA    DATETIME     NULL CONSTRAINT DF_CF_SAL_BCO_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF VARCHAR(50)  NULL,
        FECHA_MODIF   DATETIME     NULL CONSTRAINT DF_CF_SAL_BCO_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA  VARCHAR(50)  NULL,
        FECHA_BAJA    DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_BANCO PRIMARY KEY CLUSTERED (NRO_BANCO)
    );

    PRINT 'Creada RO_T_CASHFLOW_SALDOS_BANCO.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_SALDOS_BANCO ya existia: no se toco.';
GO
