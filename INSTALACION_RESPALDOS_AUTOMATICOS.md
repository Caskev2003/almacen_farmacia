# Corrección de respaldos automáticos

Esta actualización deja un solo proceso de respaldo para Ubuntu Server y
programa ejecuciones diarias a:

- 08:00 a. m.
- 12:00 p. m.
- 05:00 p. m. (17:00)

La hora utilizada es `America/Mexico_City`.

## Qué estaba causando el problema

El módulo `public/respaldos.php` estaba configurado con:

- `C:\xampp\mysql\bin\mysqldump.exe`;
- la carpeta interna `backups/inventario`.

Esos datos sirven para Windows, pero no para Ubuntu. El respaldo automático
anterior utilizaba `/backups/almacen_farmacia`, por lo que el módulo web tampoco
estaba consultando la misma carpeta.

Esta corrección utiliza en Ubuntu:

- `/usr/bin/mysqldump`;
- socket `/run/mysqld/mysqld.sock`;
- base `almacen_farmacia`;
- carpeta `/backups/almacen_farmacia`;
- script `/usr/local/bin/backup_almacen.sh`;
- programación `/etc/cron.d/backup_almacen`.

## Archivos que debes copiar

Coloque estos archivos en el proyecto respetando las carpetas:

1. `public/respaldos.php`
2. `deployment/backup_almacen.sh`
3. `deployment/almacen-backup.cnf`
4. `deployment/backup_almacen.cron`
5. `deployment/backup_almacen.sudoers`
6. `deployment/instalar_respaldos_automaticos.sh`

## Instalación en Ubuntu Server

Después de copiar los archivos, entre por SSH y ejecute:

```bash
cd /var/www/almacen-farmacia
sudo bash deployment/instalar_respaldos_automaticos.sh
```

El instalador:

1. crea `/backups/almacen_farmacia`;
2. instala el script en `/usr/local/bin/backup_almacen.sh`;
3. instala el horario de las 08:00, 12:00 y 17:00;
4. habilita y reinicia `cron`;
5. configura el botón **Generar respaldo ahora**;
6. elimina únicamente entradas antiguas de `backup_almacen.sh` y
   `backup_inventario.sh` en el crontab de `root`, evitando respaldos
   duplicados;
7. genera un respaldo de prueba inmediatamente.

Al finalizar debe aparecer:

```text
INSTALACIÓN CORRECTA
Horario: todos los días a las 08:00, 12:00 y 17:00.
Carpeta: /backups/almacen_farmacia
```

## Si MySQL solicita contraseña

La configuración inicial usa el usuario `root` mediante el socket de Ubuntu.
Si el servidor configuró una contraseña para ese usuario, edite:

```bash
sudo nano /etc/almacen-backup.cnf
```

Debe quedar así:

```ini
[client]
user=root
password=SU_CONTRASEÑA_MYSQL
socket=/run/mysqld/mysqld.sock
default-character-set=utf8mb4
```

Guarde con `Ctrl + O`, `Enter`, cierre con `Ctrl + X` y pruebe:

```bash
sudo /usr/local/bin/backup_almacen.sh
```

No escriba la contraseña directamente dentro del cron ni del script.

## Verificación

Ejecute:

```bash
sudo systemctl status cron --no-pager
sudo cat /etc/cron.d/backup_almacen
sudo /usr/local/bin/backup_almacen.sh
sudo ls -lh /backups/almacen_farmacia
sudo tail -n 50 /var/log/backup_almacen.log
sudo tail -n 50 /var/log/backup_almacen_cron.log
```

En `/etc/cron.d/backup_almacen` debe aparecer:

```cron
0 8,12,17 * * * root /usr/local/bin/backup_almacen.sh >> /var/log/backup_almacen_cron.log 2>&1
```

También puede abrir el módulo **Respaldos** del sistema. Ahora muestra:

- la carpeta real del servidor;
- el horario automático;
- el último respaldo correcto;
- el último error automático, si ocurrió;
- todos los archivos disponibles para descargar.

## Compatibilidad con phpMyAdmin de Windows

El script genera archivos `.sql` sin comprimir y reemplaza collations exclusivas
de MySQL nuevo por `utf8mb4_unicode_ci`. También elimina los `DEFINER` que
normalmente provocan errores al importar el respaldo en XAMPP/phpMyAdmin.
