ICAR-import
===========

With the HTML-page 'index.html', data from the ICAR-adressenlijst dataset can be imported into JOSM on a per postcode / per street basis. The up-to-date version of the tool is found at http://icar-import.osm.be/

The JSON-files in the data folder are derived from the ICAR-adressenlijst dataset using the python import script `extract.py`. So they fall under the Free Open Data License (Flanders v1.0.).  Wallonia equivalent needed here.

DATA Update
===========

Updating the data is rather simple, but you have to make sure that all the removed streetnames are actually gone, and that the new streetnames are added.

1. Make sure this git repo is updated (`git pull`)
2. First download the shapefile zips from the Wallonia somehow 
   try:
  ```
	http://geoportail.wallonie.be/catalogue/2998bccd-dae4-49fb-b6a5-867e6c37680f.html  
  ```
3. Extract the zip on your computer, you need the entire Shapefile directory together (so not only the .shp file).
4. Delete the `data` directory (`rm -r data`) to remove the old streets
5. Run the extract script with Python 2 (either called python or python2 depending on your system).
  ```
  python2 extract.py ../path/to/Shapefile/ADR.shp
  ```
6. Add the new streets to git (`git add --all data/*`). If you forget this step, places where new streets are created won't load anymore.
7. Update the extraction date in the `import.html` file
8. Commit the changes (`git commit -a -m "Updated data to yyyy-mm-dd"`)
9. Push the changes to the online repo (`git push origin master`)
