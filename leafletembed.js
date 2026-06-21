var map;
var ajaxRequest;
var plotlist;
var plotlayers=[];

function initmap() {
	// set up the map
	map = new L.Map('map');

	// Tile layer. OSM attribution is provided here the layer-based way (Leaflet
	// collects it dynamically); the data-source / route credits are added in
	// map.html as the control prefix, so the whole line is '|'-separated.
	var osmUrl='https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
	var osmAttrib='&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">Contribuidores de OpenStreetMap</a>';
	var osm = new L.TileLayer(osmUrl, {maxZoom: 18, attribution: osmAttrib});

	// start the map in South-East England
	map.setView(new L.LatLng(41.629, -0.879),9);
	map.addLayer(osm);
}
