SELECT id, sname, app_nr, bus_nr, house_nr, ST_AsGeoJSON(ST_Transform(coord,4326),15,4) AS geojson FROM addresses 
WHERE coord && ST_SetSRID('BOX3D()'::box3d, 4326);
