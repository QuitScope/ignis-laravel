<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects docredir with 308 and keeps the query', function () {
    foreach ([
        ['/assets/functions/docredir?docid=AB12-CD34-EF56', '/personnel/document-view?docid=AB12-CD34-EF56'],
        ['/assets/functions/docredir.php?docid=1234567', '/personnel/document-view?docid=1234567'],
        ['/assets/functions/docredir?docid[]=x&docid[a]=y', '/personnel/document-view?docid[]=x&docid[a]=y'],
        ['/assets/functions/docredir', '/personnel/document-view'],
    ] as [$from, $to]) {
        $this->get($from)->assertStatus(308)->assertRedirect($to);
    }
});

it('redirects the old log link to the document view', function () {
    foreach ([
        ['/mitarbeiter/dokument-view?docid=1234567', '/personnel/document-view?docid=1234567'],
        ['/mitarbeiter/dokument-view.php?docid=AB12-CD34-EF56', '/personnel/document-view?docid=AB12-CD34-EF56'],
    ] as [$from, $to]) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
});

it('maps /dokumente/* to /documents/* and keeps the query', function () {
    $this->get('/dokumente/12/pdf?x=1')->assertStatus(301)->assertRedirect('/documents/12/pdf?x=1');
});

it('has no POST /personnel/profile any more (new=6 dropped)', function () {
    $this->post('/personnel/profile', ['new' => 6])->assertNotFound();
});
