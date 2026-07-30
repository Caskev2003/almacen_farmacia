'use strict';

self.addEventListener('notificationclick', (evento) => {
    evento.notification.close();

    const destino =
        evento.notification.data?.destino || './';
    const urlDestino = new URL(
        destino,
        self.registration.scope
    ).href;

    evento.waitUntil(
        self.clients
            .matchAll({
                type: 'window',
                includeUncontrolled: true
            })
            .then((ventanas) => {
                const ventanaAbierta = ventanas.find(
                    (ventana) =>
                        ventana.url.startsWith(
                            self.registration.scope
                        )
                );

                if (ventanaAbierta) {
                    ventanaAbierta.navigate(urlDestino);
                    return ventanaAbierta.focus();
                }

                return self.clients.openWindow(urlDestino);
            })
    );
});
