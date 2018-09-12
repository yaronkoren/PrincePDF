function datestamp() {
  var currentTime = new Date()
  var month = currentTime.getMonth() + 1
  var day = currentTime.getDate()
  var year = currentTime.getFullYear()
  return month + "/" + day + "/" + year;
}
Prince.addScriptFunc("datestamp", datestamp);

function addTheadTagToTable() {
  var tables = document.getElementsByTagName("table");
  if (tables) {
    var tmpChild;
    var tmpRow;
    //Loop in all  table in the docs
    for (var i = tables.length - 1; i >= 0; i--) {
      var maxColumns = 0
        //loop each children of table
      for (var x = tables[i].childNodes.length - 1; x >= 0; x--) {
        tmpChild = tables[i].childNodes[x]
          // //find the <tbody> tag
        if (tmpChild.childNodes.length > 0 && tmpChild.childNodes[0] && tmpChild.childNodes[0].childNodes[0] && (tmpChild.tagName == 'TBODY' || tmpChild.tagName == 'tbody')) {
          var innerHTML = ""
          var hasHeader = false;
          //check if tbody has children tr > th
          //for each rows tr in tbody
          for (var w = 0; w < tmpChild.childNodes.length - 1; w++) {
            //check if the row has headers
            tmpRow = tmpChild.childNodes
            if (tmpRow && tmpRow[w].childNodes && tmpRow[w].childNodes.length > 0) {

              for (var x = tmpRow[w].childNodes.length - 1; x >= 0; x--) {

                if (tmpRow[w].childNodes[x].nodeType == Node.ELEMENT_NODE && (tmpRow[w].childNodes[x].tagName == "TH" || tmpRow[w].childNodes[x].tagName == "th")) {
                  hasHeader = true;
                  break;
                }
              }
            }
            if (hasHeader) {
              //get innerHTML of headers
              innerHTML += tmpRow[w].innerHTML
                //and delete it
              tmpChild.removeChild(tmpRow[w])
              break;
            }
          }
          //create thead element and append all TH header before the tbody tag
          var thead = document.createElement("thead")
          thead.innerHTML = innerHTML;
          tables[i].insertBefore(thead, tmpChild);
          break;
        }
      }
    }
  }
}
// Execution
addTheadTagToTable()
