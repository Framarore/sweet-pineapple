# Proyecto: Tienda Online para Pastelería (Drupal)

Este paquete contiene el código fuente y la base de datos del proyecto de tienda online desarrollado con Drupal 10 y Drupal Commerce. El entorno de desarrollo está gestionado por DDEV para garantizar una fácil ejecución.

## Prerrequisitos

Para ejecutar este proyecto, es necesario tener instalado el siguiente software:

1.  **Docker Desktop:** [https://www.docker.com/products/docker-desktop/](https://www.docker.com/products/docker-desktop/)
2.  **DDEV:** [https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/)

## Instrucciones de Instalación

1.  **Descomprimir:** Descomprime el archivo `.zip` de este proyecto en una carpeta de tu elección en tu computadora.

2.  **Abrir Terminal:** Abre una terminal o línea de comandos (como PowerShell preferiblemente).

3.  **Navegar al Proyecto:** Usa el comando `cd` para navegar hasta la carpeta que acabas de descomprimir.
    
    # Ejemplo:
    # cd "C:\Users\TuUsuario\Desktop\pasteleria-drupal"
    

4.  **Iniciar el Entorno:** Ejecuta el siguiente comando. DDEV leerá la configuración, descargará las imágenes necesarias e importará automáticamente la base de datos que viene en el paquete.
    ```
    ddev start
    ```

5.  **Acceder al Sitio:** Una vez que el comando termine, el sitio estará funcionando. Para ver las URLs de acceso, puedes ejecutar:
    ```
    ddev describe
    ```
    Busca la URL junto a ` Project URLs ` y ábrela en tu navegador.

## Credenciales de Acceso

Para acceder al panel de administración de Drupal:

* **Usuario:** `admin`
* **Contraseña:** `admin`