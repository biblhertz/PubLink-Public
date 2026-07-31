/** */
export default class AnnototAdapter {
  /** */
  constructor(canvasId, endpointUrl,userId) {
    this.canvasId = canvasId;
    this.endpointUrl = endpointUrl;
    this.userId=userId;
  }

  /** getters : exercise the annotation API */
  get annotationPageId() {
    return `${this.endpointUrl}/pages?uri=${this.canvasId}&&user=${this.userId}`;
  }

  get createId() {
    return `${this.endpointUrl}/create?user=${this.userId}`;
  }

  get deleteId() {
    return `${this.endpointUrl}/delete`;
  }

  get updateId() {
    return `${this.endpointUrl}/update`;
  }



  /** */
  async create(annotation) {
    console.log(JSON.stringify(annotation));
    console.log("User is "+this.userId);
    
    return fetch(this.createId, {
      body: JSON.stringify({
        annotation: {
          canvas: this.canvasId,
          data: JSON.stringify(annotation),
          uuid: annotation.id,
          creator: this.userId,
        },
      }),
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      method: 'POST',
    })
      .then((response) => this.all())
      .catch(() => this.all());
  }

  /** */
  async update(annotation) {
    return fetch(this.updateId, {
      body: JSON.stringify({
        annotation: {
          canvas: this.canvasId,
          data: JSON.stringify(annotation),
          uuid: annotation.id,
          creator: this.userId,
        },
      }),
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      method: 'POST',
    })
      .then((response) => this.all())
      .catch(() => this.all());
  }


  async delete(annoId) {
      return fetch(this.deleteId, {
          body: JSON.stringify({
            uuid: annoId,
            creator: this.userId,
            canvas: this.canvasId,
            },
          ),
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          method: 'POST',
        })
          .then((response) => this.all())
          .catch(() => this.all());
  }
    
  

  /** */
  async get(annoId) {
    return (await fetch(`${this.endpointUrl}/${encodeURIComponent(annoId)}`, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })).json();
  }

  /** */
  async all() {
    let page=(await fetch(this.annotationPageId)).json();
    
    page.then(function(result) {
      console.log("ALL :: "+JSON.stringify(result));
  });
  
    return page;
  }
}
