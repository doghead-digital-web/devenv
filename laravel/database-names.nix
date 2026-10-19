appName: worktreeName:

let
  assertMsg = pred: msg: pred || builtins.throw msg;

  assertDatabaseName =
    name:
    assert assertMsg (builtins.match "[a-z0-9_]+" name != null)
      "Worktree '${worktreeName}' would get the database name ${name}, which is not only lowercase letters, digits and underscores. Rename the worktree.";
    name;

  dbName =
    let
      name = assertDatabaseName (
        "${appName}_" + builtins.replaceStrings [ "-" "." "/" ] [ "_" "_" "_" ] worktreeName
      );
    in
    assert assertMsg (builtins.match ".*_test(_[0-9]+)?" name == null)
      "Worktree '${worktreeName}' would get the dev database ${name}, which is named like a test database and can be another worktree's. Rename the worktree so it does not end in -test or -test-<N>.";
    name;

  testDbName =
    let
      perWorktree = "${dbName}_test";
      maxLength = 64 - builtins.stringLength "_test_999";
      hash = builtins.substring 0 8 (builtins.hashString "sha256" worktreeName);
      hashed = "_${hash}_test";
    in
    assertDatabaseName (
      if builtins.stringLength perWorktree <= maxLength then
        perWorktree
      else
        builtins.substring 0 (maxLength - builtins.stringLength hashed) dbName + hashed
    );
in
{
  inherit dbName testDbName;
}
