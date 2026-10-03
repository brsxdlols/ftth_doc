/* Adaptador Leaflet para as operações usadas pelo editor FTTH.
 * Carregado exclusivamente quando o provedor é OpenStreetMap; não carrega a API Google. */
(function () {
    'use strict';
    function coord(p, lng) {
        if (Array.isArray(p)) return L.latLng(p[0], p[1]);
        if (typeof p === 'number') return L.latLng(p, lng);
        return L.latLng(typeof p.lat === 'function' ? p.lat() : p.lat,
            typeof p.lng === 'function' ? p.lng() : p.lng);
    }
    function LatLng(p, lng) { this.p = coord(p, lng); }
    LatLng.prototype.lat = function () { return this.p.lat; };
    LatLng.prototype.lng = function () { return this.p.lng; };
    function Bounds(a, b) { this.b = L.latLngBounds([]); if (a) this.extend(a); if (b) this.extend(b); }
    Bounds.prototype.extend = function (p) { this.b.extend(coord(p)); return this; };
    Bounds.prototype.getSouthWest = function () { return new LatLng(this.b.getSouthWest()); };
    Bounds.prototype.getNorthEast = function () { return new LatLng(this.b.getNorthEast()); };
    function Events() { this.listeners = {}; }
    Events.prototype.addListener = function (name, fn) {
        var list = this.listeners[name] || (this.listeners[name] = []);
        list.push(fn);
        return { remove: function () { var i = list.indexOf(fn); if (i >= 0) list.splice(i, 1); } };
    };
    Events.prototype.emit = function (name, arg) {
        (this.listeners[name] || []).slice().forEach(function (fn) { fn(arg); });
    };
    function inherit(Type) { Type.prototype = Object.create(Events.prototype); Type.prototype.constructor = Type; }
    function Map(el, opts) {
        Events.call(this);
        var self = this;
        this.l = L.map(el, { maxZoom: 21, doubleClickZoom: false }).setView(coord(opts.center), opts.zoom);
        this.bases = {};
        this.bases.roadmap = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxNativeZoom: 19, maxZoom: 21,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        });
        this.bases.satellite = L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxNativeZoom: 19, maxZoom: 21,
            attribution: 'Imagens &copy; <a href="https://www.arcgis.com/home/item.html?id=10df2279f9684e4a9f6a7f08febac2a9">Esri</a>, Maxar, Earthstar Geographics e GIS User Community'
        });
        this.tipo = 'roadmap';
        try { this.tipo = localStorage.getItem('ftth_mapa_base') === 'satellite' ? 'satellite' : 'roadmap'; } catch (e) {}
        this.bases[this.tipo].addTo(this.l);
        var ControleBase = L.Control.extend({ options: { position: 'topright' }, onAdd: function () {
            var div = L.DomUtil.create('div', 'leaflet-bar ftth-osm-bases');
            self.botoesBase = {};
            ['roadmap', 'satellite'].forEach(function (tipo) {
                var b = document.createElement('button'); b.type = 'button';
                b.textContent = tipo === 'roadmap' ? 'Mapa' : 'Satélite';
                b.classList.toggle('ativo', tipo === self.tipo);
                b.setAttribute('aria-pressed', String(tipo === self.tipo));
                b.addEventListener('click', function () { self.setMapTypeId(tipo); });
                self.botoesBase[tipo] = b; div.appendChild(b);
            });
            L.DomEvent.disableClickPropagation(div); L.DomEvent.disableScrollPropagation(div);
            return div;
        } });
        new ControleBase().addTo(this.l);
        this.l.zoomControl.setPosition('topright');
        L.control.scale({ imperial: false }).addTo(this.l);
        this.overlayMapTypes = { getLength: function () { return 0; }, insertAt: function () {}, clear: function () {} };
        this.l.on('moveend', function () { self.emit('idle'); });
        this.l.on('click', function (e) { self.emit('click', { latLng: new LatLng(e.latlng), domEvent: e.originalEvent }); });
        this.l.whenReady(function () { setTimeout(function () { self.emit('idle'); }, 0); });
        if (window.ResizeObserver) new ResizeObserver(function () { self.l.invalidateSize(); }).observe(el);
    }
    inherit(Map);
    Map.prototype.getCenter = function () { return new LatLng(this.l.getCenter()); };
    Map.prototype.getZoom = function () { return this.l.getZoom(); };
    Map.prototype.setCenter = function (p) { this.l.panTo(coord(p), { animate: false }); };
    Map.prototype.setZoom = function (z) { this.l.setZoom(z, { animate: false }); };
    Map.prototype.getBounds = function () { var b = new Bounds(); b.b = this.l.getBounds(); return b; };
    Map.prototype.fitBounds = function (b, padding) {
        var n = typeof padding === 'number' ? padding : 60;
        this.l.fitBounds(b.b, { padding: [n, n], maxZoom: 19, animate: false });
        // Google também emite idle quando a vista solicitada já é a atual.
        var self = this; setTimeout(function () { self.emit('idle'); }, 0);
    };
    Map.prototype.setOptions = function (o) { this.l.getContainer().style.cursor = o.draggableCursor || ''; };
    Map.prototype.getMapTypeId = function () { return this.tipo; };
    Map.prototype.setMapTypeId = function (tipo) {
        tipo = tipo === 'satellite' || tipo === 'hybrid' ? 'satellite' : 'roadmap';
        if (tipo === this.tipo) return;
        this.l.removeLayer(this.bases[this.tipo]); this.tipo = tipo; this.bases[tipo].addTo(this.l);
        var self = this;
        Object.keys(this.botoesBase || {}).forEach(function (t) {
            self.botoesBase[t].classList.toggle('ativo', t === tipo);
            self.botoesBase[t].setAttribute('aria-pressed', String(t === tipo));
        });
        try { localStorage.setItem('ftth_mapa_base', tipo); } catch (e) {}
        this.emit('maptypeid_changed');
    };
    Map.prototype.getProjection = function () {
        return { fromLatLngToPoint: function (p) { return L.CRS.EPSG3857.latLngToPoint(coord(p), 0); } };
    };
    function icon(o) {
        if (o.url) return L.icon({ iconUrl: o.url, iconSize: [o.scaledSize.width, o.scaledSize.height],
            iconAnchor: [o.anchor.x, o.anchor.y] });
        var r = o.scale || 6, d = r * 2 + 8;
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + d + '" height="' + d + '">' +
            '<circle cx="' + d / 2 + '" cy="' + d / 2 + '" r="' + r + '" fill="' + (o.fillColor || '#fff') +
            '" fill-opacity="' + (o.fillOpacity === undefined ? 1 : o.fillOpacity) + '" stroke="' +
            (o.strokeColor || '#333') + '" stroke-width="' + (o.strokeWeight || 2) + '"/></svg>';
        return L.icon({ iconUrl: 'data:image/svg+xml,' + encodeURIComponent(svg), iconSize: [d, d], iconAnchor: [d / 2, d / 2] });
    }
    function Marker(o) {
        Events.call(this); var self = this;
        this.l = L.marker(coord(o.position), { icon: icon(o.icon || {}), draggable: !!o.draggable,
            interactive: o.clickable !== false, bubblingMouseEvents: false, zIndexOffset: o.zIndex || 0,
            opacity: o.opacity === undefined ? 1 : o.opacity, title: o.title || '' });
        ['click', 'drag', 'dragend'].forEach(function (name) {
            self.l.on(name, function (e) { self.emit(name, { latLng: self.getPosition(), domEvent: e.originalEvent }); });
        });
        if (o.label) {
            var span = document.createElement('span'); span.textContent = typeof o.label === 'string' ? o.label : o.label.text;
            this.l.bindTooltip(span, { permanent: true, direction: 'top', offset: [0, -16], className: 'ftth-osm-label' });
        }
        this.setMap(o.map || null);
    }
    inherit(Marker);
    Marker.prototype.setMap = function (m) { this.l.remove(); this.map = m; if (m) this.l.addTo(m.l); };
    Marker.prototype.getPosition = function () { return new LatLng(this.l.getLatLng()); };
    Marker.prototype.setPosition = function (p) { this.l.setLatLng(coord(p)); };
    Marker.prototype.setIcon = function (o) { this.l.setIcon(icon(o)); };
    Marker.prototype.setAnimation = function (a) { this.animation = a; var el = this.l.getElement(); if (el) el.classList.toggle('ftth-osm-bounce', !!a); };
    Marker.prototype.getAnimation = function () { return this.animation || null; };
    function Path(points, changed) { Events.call(this); this.points = points.map(function (p) { return new LatLng(p); }); this.changed = changed; }
    inherit(Path);
    Path.prototype.getLength = function () { return this.points.length; };
    Path.prototype.getAt = function (i) { return this.points[i]; };
    Path.prototype.forEach = function (fn) { this.points.forEach(fn); };
    Path.prototype.setAt = function (i, p) { this.points[i] = new LatLng(p); this.changed(); this.emit('set_at', i); };
    Path.prototype.insertAt = function (i, p) { this.points.splice(i, 0, new LatLng(p)); this.changed(); this.emit('insert_at', i); };
    Path.prototype.removeAt = function (i) { var p = this.points.splice(i, 1)[0]; this.changed(); this.emit('remove_at', i); return p; };
    function Polyline(o, polygon) {
        Events.call(this); var self = this;
        this.opts = o; this.handles = [];
        this.path = new Path(o.path || o.paths || [], function () { self.redraw(); });
        this.l = (polygon ? L.polygon : L.polyline)(this.path.points.map(coord), {
            color: o.strokeColor, weight: o.strokeWeight, opacity: o.strokeOpacity === undefined ? 1 : o.strokeOpacity,
            fillColor: o.fillColor, fillOpacity: o.fillOpacity, interactive: o.clickable !== false, bubblingMouseEvents: false
        });
        this.l.on('click', function (e) { self.emit('click', { latLng: new LatLng(e.latlng), domEvent: e.originalEvent }); });
        this.l.on('contextmenu', function (e) { self.emit('rightclick', { latLng: new LatLng(e.latlng), domEvent: e.originalEvent }); });
        this.setMap(o.map || null); if (o.editable) this.setEditable(true);
    }
    inherit(Polyline);
    Polyline.prototype.setMap = function (m) { this.l.remove(); this.map = m; if (m) this.l.addTo(m.l); this.editHandles(); };
    Polyline.prototype.getPath = function () { return this.path; };
    Polyline.prototype.setPath = function (pts) { var self = this; this.path = new Path(pts, function () { self.redraw(); }); this.redraw(); };
    Polyline.prototype.setPaths = Polyline.prototype.setPath;
    Polyline.prototype.redraw = function () { this.l.setLatLngs(this.path.points.map(coord)); this.editHandles(); };
    Polyline.prototype.setOptions = function (o) {
        Object.assign(this.opts, o);
        this.l.setStyle({ color: this.opts.strokeColor, weight: this.opts.strokeWeight,
            opacity: this.opts.strokeOpacity === undefined ? 1 : this.opts.strokeOpacity, fillOpacity: this.opts.fillOpacity });
        if (o.zIndex !== undefined) this.l.bringToFront();
        var el = this.l.getElement(); if (el && o.cursor !== undefined) el.style.cursor = o.cursor || '';
        if (el && o.clickable !== undefined) el.style.pointerEvents = o.clickable ? '' : 'none';
    };
    Polyline.prototype.setEditable = function (v) { this.editable = v; this.editHandles(); };
    Polyline.prototype.editHandles = function () {
        if (this.draggingHandle) return;
        this.handles.forEach(function (h) { h.remove(); }); this.handles = [];
        if (!this.editable || !this.map) return;
        var self = this, points = this.path.points;
        function handle(p, i, middle) {
            var h = L.marker(coord(p), { draggable: true, bubblingMouseEvents: false,
                icon: L.divIcon({ className: 'ftth-osm-handle' + (middle ? ' ftth-osm-middle' : ''), iconSize: [12, 12], iconAnchor: [6, 6] }) }).addTo(self.map.l);
            h.on('dragstart', function () { self.draggingHandle = true; if (middle) self.path.insertAt(i, h.getLatLng()); });
            h.on('drag', function () { self.path.setAt(i, h.getLatLng()); });
            h.on('dragend', function () { self.draggingHandle = false; self.path.setAt(i, h.getLatLng()); });
            h.on('contextmenu', function (e) { self.emit('rightclick', { vertex: middle ? undefined : i, latLng: new LatLng(h.getLatLng()), domEvent: e.originalEvent }); });
            self.handles.push(h);
        }
        points.forEach(function (p, i) {
            handle(p, i, false);
            if (i) handle({ lat: (points[i - 1].lat() + p.lat()) / 2, lng: (points[i - 1].lng() + p.lng()) / 2 }, i, true);
        });
    };
    function Polygon(o) { Polyline.call(this, o, true); }
    Polygon.prototype = Object.create(Polyline.prototype);
    window.google = { maps: { Map: Map, Marker: Marker, Polyline: Polyline, Polygon: Polygon, LatLng: LatLng,
        LatLngBounds: Bounds, Size: function (w, h) { this.width = w; this.height = h; },
        Point: function (x, y) { this.x = x; this.y = y; }, SymbolPath: { CIRCLE: 'circle' },
        Animation: { BOUNCE: 'bounce' }, StyledMapType: function () {},
        event: { addListenerOnce: function (obj, name, fn) { var listener = obj.addListener(name, function (e) { listener.remove(); fn(e); }); return listener; } }
    } };
})();
