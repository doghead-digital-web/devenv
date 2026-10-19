# A single-line rg naming a guarded word is a search, not a run, as long as
# nothing in it could run a command. The guards that block a word share this.
{
  search = "^rg( ([A-Za-z0-9_./:=,*@+-]+|'[^']*'))+$";
  refusals = [
    "[\"$\\\\`;&|<>(){}]"
    "(^| )'?--(pre|search-zip|hostname-bin)"
    "(^| )'?-[A-Za-z0-9]*z"
  ];
}
