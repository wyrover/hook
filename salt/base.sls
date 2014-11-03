{% set user = salt['pillar.get']('project_username','vagrant') %}

git:
  pkg.installed

git_https:
  cmd.run: 
    - name: git config --global url.ssh://git@github.com/.insteadOf https://github.com/
    - user: {{ user }}
    - require: 
      - pkg: git
