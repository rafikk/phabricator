<?php

final class DoorkeeperBridgeAsana extends DoorkeeperBridge {

  public function canPullRef(DoorkeeperObjectRef $ref) {
    return ($ref->getApplicationType() == 'asana') &&
           ($ref->getApplicationDomain() == 'asana.com') &&
           ($ref->getObjectType() == 'asana:task');
  }

  public function pullRefs(array $refs) {

    $id_map = mpull($refs, 'getObjectID', 'getObjectKey');
    $viewer = $this->getViewer();

    $provider = PhabricatorAuthProviderOAuthAsana::getAsanaProvider();
    if (!$provider) {
      return;
    }

    $accounts = id(new PhabricatorExternalAccountQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withAccountTypes(array($provider->getProviderType()))
      ->withAccountDomains(array($provider->getProviderDomain()))
      ->execute();

    // TODO: If the user has several linked Asana accounts, we just pick the
    // first one arbitrarily. We might want to try using all of them or do
    // something with more finesse. There's no UI way to link multiple accounts
    // right now so this is currently moot.
    $account = head($accounts);

    $token = $account->getProperty('oauth.token');
    if (!$token) {
      return;
    }

    $template = id(new PhutilAsanaFuture())
      ->setAccessToken($token);

    $futures = array();
    foreach ($id_map as $key => $id) {
      $futures[$key] = id(clone $template)
        ->setRawAsanaQuery("tasks/{$id}");
    }

    $results = array();
    foreach (Futures($futures) as $key => $future) {
      $results[$key] = $future->resolve();
    }

    foreach ($refs as $ref) {
      $result = idx($results, $ref->getObjectKey());
      if (!$result) {
        continue;
      }

      $ref->setIsVisible(true);
      $ref->setAttribute('asana.data', $result);
      $ref->setAttribute('name', $result['name']);
      $ref->setAttribute('description', $result['notes']);

      $obj = $ref->getExternalObject();
      if ($obj->getID()) {
        continue;
      }

      $id = $result['id'];
      $uri = "https://app.asana.com/0/{$id}/{$id}";
      $obj->setObjectURI($uri);

      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
        $obj->save();
      unset($unguarded);
    }
  }

}