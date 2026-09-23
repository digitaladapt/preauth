# preauth build config — one published variant from one Dockerfile.
#
#   docker buildx bake                     # build, no push
#   docker buildx bake --push              # build and push
#   docker buildx bake --print             # resolve and print, without building
#   MAX_REQUESTS=0 docker buildx bake      # override any variable
#
# CI (.gitea/workflows/develop.yaml, docker.yaml) invokes this, so the build
# definition lives here rather than in the workflow files.
#
# Naming contract (portfolio, identical to context-shuttle and task-weaver):
#   DOCKERHUB_TARGET is the org/repo (Gitea Settings → Variables; value
#   digitaladapt/preauth). Tag suffixes are decided HERE, not in CI:
#     main push → :develop
#     tag push  → :latest and :<version>  (leading 'v' stripped)
#   CI sets TAG=develop for main pushes, TAG=latest + VERSION=<v-stripped> for
#   tag pushes. Both amd64 and arm64 are always built (ARM server).
#
# Variables can be overridden from the environment, e.g.:
#   DOCKERHUB_TARGET=digitaladapt/preauth TAG=develop docker buildx bake --push

variable "DOCKERHUB_TARGET" {
  default     = "digitaladapt/preauth"
  description = "Docker Hub repo/org (Gitea repo variable DOCKERHUB_TARGET)."
}

variable "TAG" {
  default     = "latest"
  description = "Base tag for this build: latest (release), develop (main push), or a version."
}

variable "VERSION" {
  default     = ""
  description = "Full version (v stripped) to also tag with; empty for develop builds."
}

variable "MAX_REQUESTS" {
  default     = "500"
  description = "Restart each FrankenPHP worker thread after N requests (0 disables). Baked in at build time; the same env var overrides it at runtime."
}

group "default" {
  targets = ["app"]
}

target "app" {
  dockerfile = "Dockerfile"
  target     = "app"
  context    = "."
  platforms  = ["linux/amd64", "linux/arm64"]

  # Layer cache. The shared docker-publish.yaml sets cache-from/cache-to for
  # its `action` backend but NOT for `bake`, so specifying it here is what keeps
  # CI builds warm. preauth compiles APCu from source (pecl) in both stages, so
  # a cold build is expensive.
  #
  # Local builds outside CI have no GHA cache service, so override:
  #   docker buildx bake --set 'app.cache-to=' --set 'app.cache-from='
  cache-from = ["type=gha"]
  cache-to   = ["type=gha,mode=max"]

  # The Dockerfile declares ARG MAX_REQUESTS=500 for plain `docker build`.
  # It is repeated explicitly here so CI's value is visible and can be changed
  # in this file instead of in a workflow. Keep the two defaults in sync.
  args = {
    MAX_REQUESTS = "${MAX_REQUESTS}"
  }

  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}"] : [],
  )
}
