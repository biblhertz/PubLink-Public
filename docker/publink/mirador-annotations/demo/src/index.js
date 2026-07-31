
import mirador from 'mirador/dist/es/src/index';
import imageCropperPlugin from 'mirador-imagecropper/es';
import annotationPlugins from '../../src';
import PublinkAdapter from '../../src/PublinkAdapter';
import { array } from 'prop-types';


let userCheckUrl = '';
let userId="unset user id";
let token="unset token";
let manifests='';


const queryString = window.location.search;
const urlParams = new URLSearchParams(queryString);
let manifest=null;

if(!urlParams.has('token')||!urlParams.has('userCheck')){
    alert(`Token or User Check service address is not set - exiting`);
    window.open('','_self').close()
    exit;
}

if(urlParams.has('manifest')){
   manifests=JSON.parse(urlParams.get('manifest'));
}
console.log("Manifests :: "+manifests);

let canvas = urlParams.has('canvas') ? urlParams.get('canvas') : null;
console.log("Canvas :: "+canvas);

token=urlParams.get('token');
userCheckUrl=urlParams.get('userCheck');
userCheckUrl=userCheckUrl+"?token="+token;
console.log("Check URL :: "+userCheckUrl);

const request = new XMLHttpRequest();
request.onload =  () => {
  // I'll run when the request completes
  const data = JSON.parse(request.response);
  if(data.user === "-1"){
    alert(`User or token doesn't exist\nYou can only use this tool from the publink interface`);
    window.open('','_self').close()
    exit;
  }
  
  userId=`${data.user}`;
  const endpointUrl = `${data.endpoint}`;

  console.log('User ::  '+userId);
  console.log('Endpoint :: '+endpointUrl);
  
  const preLoads=getManifests(manifests, canvas);

  const config = {
    annotation: {
        adapter: (canvasId) => new PublinkAdapter(canvasId, endpointUrl, userId),
        //exportLocalStorageAnnotations: true, // display annotation JSON export button
    },
    id: 'demo',
    window: {
        defaultSideBarPanel: 'annotations',
        sideBarOpenByDefault: true,
        imageCropper: {
            active: false,
            dialogOpen: false,
            enabled: true,
            roundingPrecision: 5,
            showRightsInformation: true,
        }
    },
    windows: preLoads
            
};
  
  
  mirador.viewer(config, [...annotationPlugins,...imageCropperPlugin,]);
};
request.open('GET', `${userCheckUrl}`);
request.send();


function getManifests(manifests, canvasId){
    let manarr=Array();
    console.log('Manifests :: '+manifests);
    manifests.forEach((item,index)=>{
        let win = {id: 'window_'+index, loadedManifest: item};
        if (index === 0 && canvasId) {
            win.canvasId = canvasId;
        } else {
            win.canvasIndex = 0;
        }
        manarr[index] = win;
        console.log("index :: "+index+"=> Item :: "+item);
    });
    return manarr;
}




